<?php

namespace App\Console\Commands;

use App\Models\InsDwpDevice;
use App\Models\InsDwpTimeAlarmCount;
use Carbon\Carbon;
use Illuminate\Console\Command;
use ModbusTcpClient\Composer\Read\ReadRegistersBuilder;
use ModbusTcpClient\Network\NonBlockingClient;
use ModbusTcpClient\Network\BinaryStreamConnection;
use ModbusTcpClient\Packet\ModbusFunction\WriteMultipleRegistersRequest;
use ModbusTcpClient\Packet\ModbusFunction\WriteSingleCoilRequest;
use ModbusTcpClient\Packet\ModbusFunction\WriteSingleRegisterRequest;
use ModbusTcpClient\Packet\ResponseFactory;
use ModbusTcpClient\Utils\Types;

class InsDwpPollAlarm extends Command
{
    // Configuration variables
    protected $pollIntervalSeconds = 1;
    protected $modbusTimeoutSeconds = 2;
    protected $modbusPort = 503;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:ins-dwp-poll-alarm {--v : Verbose output} {--d : Debug output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll DWP (Deep-Well Alarm Constraint Time) counter data from Modbus servers and track incremental counts';

    // In-memory buffer to track last cumulative values per line
    protected $lastCumulativeValues = [];
    private $lastDurationValues = [];
    private $lastSentDurationValues = []; // Track last sent duration per line
    public int $saveDuration = 0;

    // Reset time configuration
    protected $resetHour = 7; // Reset long duration at 7:00 AM
    protected $lastResetDate = null; // Track when last reset was performed

    // Memory optimization counters
    protected $pollCycleCount = 0;
    protected $memoryCleanupInterval = 1000; // Clean memory every 1000 cycles

    // Statistics tracking
    protected $deviceStats = [];
    protected $totalReadings = 0;
    protected $totalErrors = 0;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Get all active devices
        $devices = InsDwpDevice::active()->get();
        if ($devices->isEmpty()) {
            $this->error('✗ No active DWP devices found');
            return 1;
        }

        $this->info('✓ InsDwpPoll started - monitoring ' . count($devices) . ' devices');
        if ($this->option('v')) {
            $this->comment('Devices:');
            foreach ($devices as $device) {
                $lines = implode(', ', $device->getLines());
                $this->comment("  → {$device->name} ({$device->ip_address}) - Lines: {$lines}");
            }
        }
        // Initialize last cumulative values from database
        $this->initializeLastValues($devices);
        // Main polling loop
        while (true) {
            $cycleStartTime = microtime(true);
            $cycleReadings = 0;
            $cycleErrors = 0;

            // Check if we need to reset long duration at 7:00 AM
            $this->checkAndResetLongDuration($devices);

            foreach ($devices as $device) {
                if ($this->option('v')) {
                    $this->comment("→ Polling {$device->name} ({$device->ip_address})");
                }

                try {
                    $readings = $this->pollDevice($device);
                    $cycleReadings += $readings;
                    // send long duration for hmi
                    $this->sendLongDuration($device);
                    $this->updateDeviceStats($device->name, true);
                } catch (\Throwable $th) {
                    $this->error("✗ Error polling {$device->name} ({$device->ip_address}): " . $th->getMessage(). "Error Di Line Code ke". $th->getLine());
                    $cycleErrors++;
                    $this->updateDeviceStats($device->name, false);
                }
            }

            // Update global statistics
            $this->totalReadings += $cycleReadings;
            $this->totalErrors += $cycleErrors;

            // Display cycle statistics
            if ($this->option('v')) {
                $cycleTime = microtime(true) - $cycleStartTime;
                $this->info("Cycle #{$this->pollCycleCount}: {$cycleReadings} readings, {$cycleErrors} errors, " .
                           number_format($cycleTime * 1000, 2) . "ms");
            }

            // Sleep for configured interval before next poll
            sleep($this->pollIntervalSeconds);

            // Increment cycle count for memory management
            $this->pollCycleCount++;

            // Periodic memory cleanup
            if ($this->pollCycleCount % $this->memoryCleanupInterval === 0) {
                $this->cleanupMemory();
            }
        }
    }

    /**
     * Initialize last cumulative values from database
     */
    private function initializeLastValues($devices)
    {
        foreach ($devices as $device) {
            foreach ($device->getLines() as $line) {
                $lastCount = InsDwpTimeAlarmCount::latestForLine($line);
                if ($lastCount) {
                    $this->lastCumulativeValues[$line] = $lastCount->cumulative;
                    if ($this->option('d')) {
                        $this->line("Initialized line {$line} with last cumulative: {$lastCount->cumulative}");
                    }
                } else {
                    $this->lastCumulativeValues[$line] = null;
                    if ($this->option('d')) {
                        $this->line("Line {$line} has no previous data - will skip first reading");
                    }
                }
            }
        }
    }

    private function sendLongDuration($device)
    {
        // GET LONG DURATION DATA from database (today's max)
        $longDurationData = InsDwpTimeAlarmCount::orderBy('duration', 'desc')
            ->whereBetween('created_at', [
                Carbon::now()->startOfDay(),
                Carbon::now()->endOfDay()
            ])
            ->first();
        
        // No data found - nothing to send
        if (empty($longDurationData)) {
            if ($this->option('d')) {
                $this->line("  No long duration data found for today");
            }
            return;
        }

        $line = $longDurationData->line;
        $currentMaxDuration = $longDurationData->duration;
        
        // Get the last sent duration for this line
        $lastSent = $this->lastSentDurationValues[$line] ?? null;
        
        // Only send if the duration has INCREASED (new maximum detected)
        if ($lastSent === null || $currentMaxDuration > $lastSent) {
            try {
                // Get register address from config or use default
                $registerAddr = $device->config[0]['dwp_alarm']['addr_long_duration'] ?? 609;
                
                $connection = BinaryStreamConnection::getBuilder()
                    ->setHost($device->ip_address)
                    ->setPort($this->modbusPort)
                    ->build();

                $packet = new WriteSingleRegisterRequest(
                    609,              // Register address
                    $currentMaxDuration,        // Value
                    1                           // Unit ID
                );

                $connection->connect();
                $connection->send($packet);
                $connection->close();
                
                // Update last sent value
                $this->lastSentDurationValues[$line] = $currentMaxDuration;
                
                if ($this->option('v')) {
                    $this->info("  ✓ Sent max duration to {$device->name}: {$currentMaxDuration} (previous: " . ($lastSent ?? 'none') . ")");
                }
                
                return true;
            } catch (\Exception $e) {
                $this->error("    ✗ Error sending long duration to {$device->name} ({$device->ip_address}): " . $e->getMessage() . " at line " . $e->getLine());
                return false;
            }
        } else {
            if ($this->option('d')) {
                $this->line("  No update needed - current max: {$currentMaxDuration}, last sent: {$lastSent}");
            }
        }
    }

    /**
     * Check if it's time to reset long duration (at 7:00 AM daily)
     * Resets the lastSentDurationValues to allow sending new max duration
     */
    private function checkAndResetLongDuration($devices)
    {
        $now = Carbon::now();
        $currentHour = (int) $now->format('H');
        $currentDate = $now->format('Y-m-d');

        // Check if we're at or past reset hour (7:00 AM) and haven't reset today
        if ($currentHour >= $this->resetHour && $this->lastResetDate !== $currentDate) {
            if ($this->option('v')) {
                $this->info("🔄 Performing daily reset of long duration at {$now->format('H:i:s')}");
            }

            // Reset the in-memory tracking of sent durations
            $this->lastSentDurationValues = [];

            // Send reset value (0) to all devices
            foreach ($devices as $device) {
                try {
                    $this->sendResetDurationToDevice($device);
                } catch (\Exception $e) {
                    $this->error("  ✗ Error resetting duration on {$device->name}: " . $e->getMessage());
                }
            }

            // Mark reset as done for today
            $this->lastResetDate = $currentDate;

            if ($this->option('v')) {
                $this->info("✓ Long duration reset completed for {$currentDate}");
            }
        }
    }

    /**
     * Send reset (0) value to device's long duration register
     */
    private function sendResetDurationToDevice(InsDwpDevice $device)
    {
        $registerAddr = $device->config[0]['dwp_alarm']['addr_long_duration'] ?? 609;

        $connection = BinaryStreamConnection::getBuilder()
            ->setHost($device->ip_address)
            ->setPort($this->modbusPort)
            ->build();

        $packet = new WriteSingleRegisterRequest(
            $registerAddr,    // Register address
            0,                // Reset value to 0
            1                 // Unit ID
        );

        $connection->connect();
        $connection->send($packet);
        $connection->close();

        if ($this->option('v')) {
            $this->info("  ✓ Reset long duration to 0 on {$device->name} (register {$registerAddr})");
        }

        return true;
    }

    private function pollDevice(InsDwpDevice $device)
    {
        $unit_id = 1; // Standard Modbus unit ID
        $readingsCount = 0;

        foreach ($device->config as $lineConfig) {
            $line = strtoupper(trim($lineConfig['line']));
            $counterAddr = $lineConfig['dwp_alarm']['addr_counter'];
            $this->line("  Polling line {$device->ip_address} at address {$counterAddr}");
            if ($this->option('d')) {
                $this->line("  Polling line {$line} at address {$counterAddr}");
            }

            try {
                // Build Modbus request for this line's counter
                $datas = [];
                // REQUEST DATA COUNTER AND RESPONSE COUNTER
                $requestCounter = ReadRegistersBuilder::newReadInputRegisters(
                        'tcp://' . $device->ip_address . ':' . $this->modbusPort,
                        $unit_id)
                        ->int16($lineConfig['dwp_alarm']['addr_counter'], 'counter_value')
                        ->build();
                        // Execute Modbus request
                $responseCounter = (new NonBlockingClient(['readTimeoutSec' => $this->modbusTimeoutSeconds]))
                ->sendRequests($requestCounter)->getData();
                $currentCumulative = abs($responseCounter['counter_value']); // Make absolute to ensure positive values

                $requestLongDuration = ReadRegistersBuilder::newReadInputRegisters(
                        'tcp://' . $device->ip_address . ':' . $this->modbusPort,
                        $unit_id)
                        ->int16('554', 'duration')
                        ->build();
                        // Execute Modbus request
                $responseLongDuration = (new NonBlockingClient(['readTimeoutSec' => $this->modbusTimeoutSeconds]))
                ->sendRequests($requestLongDuration)->getData();

                $currentDuration = $responseLongDuration['duration'];

                if ($this->option('d')) {
                    $this->line("    Current cumulative: {$currentCumulative}");
                    $this->line("    Current duration: {$currentDuration}");
                    $this->line("    Last cumulative: " . ($this->lastCumulativeValues[$line] ?? 'null'));
                    $this->line("    Last duration: " . ($this->lastDurationValues[$line] ?? 'null'));
                }

                // Check if we have a previous value to compare against
                if ($this->lastCumulativeValues[$line] === null) {
                    // First reading - skip and store as reference
                    $this->lastCumulativeValues[$line] = $currentCumulative;
                    $this->lastDurationValues[$line] = $currentDuration;
                    if ($this->option('d')) {
                        $this->line("    First reading - storing as reference");
                    }
                    continue;
                }

                // Calculate incremental value
                $incremental = $currentCumulative - $this->lastCumulativeValues[$line];

                // Handle counter reset (if current < previous, assume reset occurred)
                if ($incremental < 0) {
                    // Counter was reset, incremental = current value
                    $incremental = $currentCumulative;
                    if ($this->option('v')) {
                        $this->comment("  → Counter reset detected for line {$line}");
                    }
                }

                // Get the latest record for this line
                $lastRecord = InsDwpTimeAlarmCount::latestForLine($line);

                // Only create new record if there's an actual increment
                if ($incremental > 0) {
                    // Before creating new record, update the duration of the previous record
                    // Use the LAST duration value (from previous poll) as the final duration
                    if (!empty($lastRecord) && isset($this->lastDurationValues[$line])) {
                        InsDwpTimeAlarmCount::where('id', $lastRecord['id'])
                            ->update(['duration' => $this->lastDurationValues[$line]]);

                        if ($this->option('d')) {
                            $this->line("    Updated final duration for previous record ID {$lastRecord['id']}: {$this->lastDurationValues[$line]}");
                        }
                    }

                    // Create new record with current duration (which might be 0 if just reset)
                    $count = new InsDwpTimeAlarmCount([
                        'line' => $line,
                        'cumulative' => $currentCumulative,
                        'incremental' => $incremental,
                        'duration' => $currentDuration,
                    ]);

                    $count->save();

                    if ($this->option('v')) {
                        $this->info("  ✓ Stored: Line {$line}, Cumulative: {$currentCumulative}, Incremental: +{$incremental}, Duration: {$currentDuration}");
                    }

                    $readingsCount++;
                } else {
                    // No new increment, just update the duration of the existing record
                    if (!empty($lastRecord)) {
                        InsDwpTimeAlarmCount::where('id', $lastRecord['id'])
                            ->update(['duration' => $currentDuration]);

                        if ($this->option('d')) {
                            $this->line("    Updated duration for current record ID {$lastRecord['id']}: {$currentDuration}");
                        }
                    }
                }

                // Store current values for next poll cycle
                $this->lastCumulativeValues[$line] = $currentCumulative;
                $this->lastDurationValues[$line] = $currentDuration;
                sleep(3);
            } catch (\Exception $e) {
                $this->error("    ✗ Error reading line {$line}: " . $e->getMessage(). $e->getLine());
                continue;
            }
        }

        return $readingsCount;
    }

    /**
     * Clean up memory by removing old entries and forcing garbage collection
     */
    private function cleanupMemory()
    {
        // Limit the lastCumulativeValues array size by keeping only active lines
        $activeLines = InsDwpDevice::active()->get()->flatMap(function ($device) {
            return $device->getLines();
        })->unique()->toArray();

        // Remove entries for lines that are no longer active
        $this->lastCumulativeValues = array_intersect_key(
            $this->lastCumulativeValues,
            array_flip($activeLines)
        );

        // Force garbage collection
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        if ($this->option('d')) {
            $memoryUsage = memory_get_usage(true);
            $this->line("Memory cleanup performed. Current usage: " . number_format($memoryUsage / 1024 / 1024, 2) . " MB");
        }
    }

    /**
     * Update device statistics
     */
    private function updateDeviceStats(string $deviceName, bool $success)
    {
        if (!isset($this->deviceStats[$deviceName])) {
            $this->deviceStats[$deviceName] = [
                'success_count' => 0,
                'error_count' => 0,
                'last_success' => null,
                'last_error' => null,
            ];
        }

        if ($success) {
            $this->deviceStats[$deviceName]['success_count']++;
            $this->deviceStats[$deviceName]['last_success'] = now();
        } else {
            $this->deviceStats[$deviceName]['error_count']++;
            $this->deviceStats[$deviceName]['last_error'] = now();
        }

        // Display periodic stats every 100 cycles in verbose mode
        if ($this->option('v') && $this->pollCycleCount % 100 === 0) {
            $stats = $this->deviceStats[$deviceName];
            $total = $stats['success_count'] + $stats['error_count'];
            $successRate = $total > 0 ? round(($stats['success_count'] / $total) * 100, 1) : 0;

            $this->comment("Device {$deviceName} stats: {$successRate}% success rate ({$stats['success_count']}/{$total})");
        }
    }
}
