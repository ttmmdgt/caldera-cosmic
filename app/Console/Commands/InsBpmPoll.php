<?php

namespace App\Console\Commands;

use App\Models\InsBpmDevice;
use App\Models\InsBpmCount;
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

class InsBpmPoll extends Command
{
    // Configuration variables
    protected $pollIntervalSeconds = 1;
    protected $modbusTimeoutSeconds = 2;
    protected $modbusPort = 503;
    public $addressWrite  = [
        'M1_Hot'   => 10,
        'M1_Cold'  => 11,
        'M2_Hot'   => 12,
        'M2_Cold'  => 13,
        'M3_Hot'   => 14,
        'M3_Cold'  => 15,
        'M4_Hot'   => 16,
        'M4_Cold'  => 17,
    ];
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:ins-bpm-poll {--v : Verbose output} {--d : Debug output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll BPM (Deep-Well Alarm Constraint Time) counter data from Modbus servers and track incremental counts';

    // In-memory buffer to track last cumulative values per line and condition
    protected  $lastCumulativeValues = []; // Format: ['M1_Hot' => 123, 'M1_Cold' => 456]
    protected  $lastReadingDates     = []; // Format: ['M1_Hot' => '2026-01-09', 'M1_Cold' => '2026-01-09']
    private    $lastDurationValues   = [];
    private    $lastSentDurationValues = []; // Track last sent duration per line
    public int $saveDuration = 0;

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
        $devices = InsBpmDevice::active()->get();
        if ($devices->isEmpty()) {
            $this->error('✗ No active BPM devices found');
            return 1;
        }
        $this->info('✓ InsBpmPoll started - monitoring ' . count($devices) . ' devices');
        if ($this->option('v')) {
            $this->comment('Devices:');
            foreach ($devices as $device) {
                $lines = implode(', ', $device->getLines());
                $this->comment("  → {$device->name} ({$device->ip_address}) - Lines: {$lines}");
            }
        }

        // forach device, poll once for testing
        foreach ($devices as $device) {
            if ($this->option('v')) {
                $this->comment("→ Polling {$device->name} ({$device->ip_address})");
            }

            try {
                $readings = $this->pollDevice($device);
                if ($readings > 0) {
                    $this->info("✓ Polling {$device->name} completed - {$readings} new readings saved");
                } else {
                    $this->info("→ Polling {$device->name} completed - no new readings");
                }
            } catch (\Throwable $th) {
                $this->error("✗ Error polling {$device->name}: " . $th->getMessage());
            }
        }
    }

    private function pollDevice(InsBpmDevice $device)
    {
        $unit_id = 1; // Standard Modbus unit ID
        $readingsCount = 0;
        $hmiUpdates = []; // Track HMI updates: ['M1_Hot' => 2, 'M1_Cold' => 3, ...]
        
        foreach ($device->config['list_mechine'] as $machineConfig) {
            $machineName = $machineConfig['name'];
            $hotAddrs    = $machineConfig['addr_hot'];
            $coldAddrs   = $machineConfig['addr_cold'];
            $line        = $machineConfig['line'] ?? $device->line;
            
            if ($this->option('d')) {
                $this->line("  Polling machine {$machineName} at addresses hot: {$hotAddrs}, cold: {$coldAddrs}");
            }

            try {
                // REQUEST DATA COUNTER HOT
                $requestConditionHot = ReadRegistersBuilder::newReadInputRegisters(
                        'tcp://' . $device->ip_address . ':' . $this->modbusPort,
                        $unit_id)
                        ->int16($hotAddrs, 'counter_hot') // Counter value at hot
                        ->build();
                // Execute Modbus request
                $responseConditionHot = (new NonBlockingClient(['readTimeoutSec' => $this->modbusTimeoutSeconds]))
                    ->sendRequests($requestConditionHot)->getData();
                $counterHot = abs($responseConditionHot['counter_hot']); // Make absolute to ensure positive values

                // REQUEST DATA COUNTER COLD
                $requestConditionCold = ReadRegistersBuilder::newReadInputRegisters(
                    'tcp://' . $device->ip_address . ':' . $this->modbusPort,
                    $unit_id)
                    ->int16($coldAddrs, 'counter_cold') // Counter value at cold
                    ->build();
                // Execute Modbus request
                $responseConditionCold = (new NonBlockingClient(['readTimeoutSec' => $this->modbusTimeoutSeconds]))
                    ->sendRequests($requestConditionCold)->getData();
                $counterCold = abs($responseConditionCold['counter_cold']); // Make absolute to ensure positive values
                
                if ($this->option('d')) {
                    $this->line("    Read values - Hot: {$counterHot}, Cold: {$counterCold}");
                }
                
                // Process HOT condition
                $hmiUpdates["M{$machineName}_Hot"] = $counterHot;
                
                // Process COLD condition
                $hmiUpdates["M{$machineName}_Cold"] = $counterCold;
            } catch (\Exception $e) {
                $this->error("    ✗ Error reading machine {$machineName}: " . $e->getMessage() . " at line " . $e->getLine());
                continue;
            }
        }

        // check condition decrement if < 11 AM decrement condition its 1 and if >=11 AM decrement condition its 2
        $currentHour = Carbon::now()->hour;
        $conditionDecrement = ($currentHour < 11) ? 2 : 3;

        $values = [
                "M1_Hot"  => max(0, ($hmiUpdates['M1_Hot'] ?? 0) - $conditionDecrement),
                "M1_Cold" => max(0, ($hmiUpdates['M1_Cold'] ?? 0) - $conditionDecrement),
                "M2_Hot"  => max(0, ($hmiUpdates['M2_Hot'] ?? 0) - $conditionDecrement),
                "M2_Cold" => max(0, ($hmiUpdates['M2_Cold'] ?? 0) - $conditionDecrement),
                "M3_Hot"  => max(0, ($hmiUpdates['M3_Hot'] ?? 0) - $conditionDecrement),
                "M3_Cold" => max(0, ($hmiUpdates['M3_Cold'] ?? 0) - $conditionDecrement),
                "M4_Hot"  => $hmiUpdates['M4_Hot'] ?? 0,
                "M4_Cold" => $hmiUpdates['M4_Cold'] ?? 0,
        ];

        // Write all HMI updates in one call
        if (!empty($hmiUpdates)) {
            $this->pushToHmi($device, $values);
        }

        // now save readings to database
        foreach ($device->config['list_mechine'] as $machineConfig) {
            $machineName = $machineConfig['name'];
            $line        = $machineConfig['line'] ?? $device->line;

            // Process HOT condition
            $newReadings = $this->processCondition($device, $line, $machineName, 'Hot', $values["M{$machineName}_Hot"] ?? 0);
            $readingsCount += $newReadings;

            // Process COLD condition
            $newReadings = $this->processCondition($device, $line, $machineName, 'Cold', $values["M{$machineName}_Cold"] ?? 0);
            $readingsCount += $newReadings;
        }

        return $readingsCount;
    }
    
    /**
     * Process a single condition (Hot or Cold) and save if changed
     */
    private function processCondition(InsBpmDevice $device, $line, string $machineName, string $condition, int $currentCumulative): int
    {
        // Skip if current cumulative is 0
        if ($currentCumulative === 0) {
            if ($this->option('d')) {
                $this->line("    → Skipping {$machineName}_{$condition} - value is 0");
            }
            return 0;
        }
        
        $key = $machineName . '_' . $condition;
        $today = Carbon::now()->toDateString();
        
        // Get latest record from database to compare
        $latestRecord = InsBpmCount::where('plant', $device->name)
            ->where('line', strtoupper(trim($line)))
            ->where('machine', strtoupper(trim($machineName)))
            ->where('condition', $condition)
            ->latest('created_at')
            ->first();
        
        // Check if cumulative value is same as in database - don't save duplicates
        if ($latestRecord && $latestRecord->cumulative === $currentCumulative) {
            // Update memory cache even though we're not saving
            $this->lastCumulativeValues[$key] = $currentCumulative;
            $this->lastReadingDates[$key] = $today;
            
            if ($this->option('d')) {
                $this->line("    → No change for {$key} - DB cumulative {$latestRecord->cumulative} = HMI {$currentCumulative} - skipping save");
            }
            return 0;
        }
        
        // Check if this is first reading today (not in memory or different day)
        if (!isset($this->lastCumulativeValues[$key]) || 
            !isset($this->lastReadingDates[$key]) || 
            $this->lastReadingDates[$key] !== $today) {  
            // First reading - if we have a DB record, calculate increment, otherwise it's initial value
            if ($latestRecord) {
                $increment = $currentCumulative - $latestRecord->cumulative;
                // Only save if increment is positive
                if ($increment > 0) {
                    InsBpmCount::create([
                        'plant' => $device->name,
                        'line' => $line,
                        'machine' => $machineName,
                        'condition' => $condition,
                        'incremental' => $increment,
                        'cumulative' => $currentCumulative,
                    ]);
                    
                    $this->lastCumulativeValues[$key] = $currentCumulative;
                    $this->lastReadingDates[$key] = $today;
                    
                    if ($this->option('d')) {
                        $this->line("    ✓ First reading today for {$key} - saved with increment {$increment}, cumulative {$currentCumulative}");
                    }
                    
                    return 1;
                } else {
                    // Negative or zero increment - update baseline
                    $this->lastCumulativeValues[$key] = $currentCumulative;
                    $this->lastReadingDates[$key] = $today;
                    
                    if ($this->option('d')) {
                        $this->line("    ⚠ Non-positive increment ({$increment}) for {$key} - updating baseline only");
                    }
                    return 0;
                }
            } else {
                // No previous record - save as initial value with increment same as cumulative
                InsBpmCount::create([
                    'plant' => $device->name,
                    'line' => $line,
                    'machine' => $machineName,
                    'condition' => $condition,
                    'incremental' => 0,
                    'cumulative' => $currentCumulative,
                ]);
                
                $this->lastCumulativeValues[$key] = $currentCumulative;
                $this->lastReadingDates[$key] = $today;
                
                if ($this->option('d')) {
                    $this->line("    ✓ Initial reading for {$key} - saved with increment 0, cumulative {$currentCumulative}");
                }
                
                return 1;
            }
        }

        // Check if value is the same as last reading in memory
        if ($currentCumulative === $this->lastCumulativeValues[$key]) {
            if ($this->option('d')) {
                $this->line("    → No change for {$key} (still {$currentCumulative}) - skipping save");
            }
            return 0;
        }

        // Value is different - calculate increment
        $increment = $currentCumulative - $this->lastCumulativeValues[$key];
        
        // Only save if increment is positive (ignore decreases/resets)
        if ($increment > 0) {
            // Save to database
            InsBpmCount::create([
                'plant' => $device->name,
                'line' => $line,
                'machine' => $machineName,
                'condition' => $condition,
                'incremental' => $increment,
                'cumulative' => $currentCumulative,
            ]);
            
            // Update last cumulative value
            $this->lastCumulativeValues[$key] = $currentCumulative;
            
            if ($this->option('d')) {
                $this->line("    ✓ Saved {$condition}: increment {$increment}, cumulative {$currentCumulative}");
            }
            
            return 1;
        } else {
            if ($this->option('d')) {
                $this->line("    ⚠ Negative increment ({$increment}) for {$key} - possible counter reset, updating baseline");
            }
            // Update baseline for counter resets
            $this->lastCumulativeValues[$key] = $currentCumulative;
            return 0;
        }
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

    // push to HMI
    private function pushToHmi(InsBpmDevice $device, array $values)
    {
        if (empty($values)) {
            return false;
        }

        $unit_id = 1; // Standard Modbus unit ID
        
        try {
            $valToSend = [
                Types::toRegister($values["M1_Hot"] ?? 0),
                Types::toRegister($values["M1_Cold"] ?? 0),
                Types::toRegister($values["M2_Hot"] ?? 0),
                Types::toRegister($values["M2_Cold"] ?? 0),
                Types::toRegister($values["M3_Hot"] ?? 0),
                Types::toRegister($values["M3_Cold"] ?? 0),
                Types::toRegister($values["M4_Hot"] ?? 0),
                Types::toRegister($values["M4_Cold"] ?? 0)
            ];

            // Step 3: Update values based on what changed
            foreach ($values as $machineKey => $counter) {
                $valueIndex = array_search($machineKey, array_keys($this->addressWrite));
                if ($valueIndex !== false) {
                    $valToSend[$valueIndex] = Types::toRegister($counter ?? 0);
                    if ($this->option('d')) {
                        $this->line("      Updated {$machineKey} = " . ($counter ?? 0));
                    }
                }
            }

            // Step 4: Write all counters to HMI in one operation
            $connection = BinaryStreamConnection::getBuilder()
                ->setHost($device->ip_address)
                ->setPort($this->modbusPort)
                ->build();
            
            $packet = new WriteMultipleRegistersRequest(
                10, // Starting address
                $valToSend, // Array of 8 values
                $unit_id
            );
            
            $connection->connect();
            $connection->send($packet);
            $connection->close();

            if ($this->option('d')) {
                $this->line("    ✓ Wrote " . count($updates) . " counter(s) to HMI in single operation");
            }

            return true;

        } catch (\Exception $e) {
            $this->error("    ✗ Error writing to HMI {$device->ip_address}: " . $e->getMessage());
            return false;
        }
    }
}
