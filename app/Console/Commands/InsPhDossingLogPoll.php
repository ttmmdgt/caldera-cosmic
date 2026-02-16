<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use ModbusTcpClient\Composer\Read\ReadRegistersBuilder;
use ModbusTcpClient\Network\NonBlockingClient;
use ModbusTcpClient\Network\BinaryStreamConnection;
use ModbusTcpClient\Packet\ModbusFunction\WriteMultipleRegistersRequest;
use ModbusTcpClient\Packet\ModbusFunction\WriteSingleCoilRequest;
use ModbusTcpClient\Packet\ModbusFunction\WriteSingleRegisterRequest;
use ModbusTcpClient\Packet\ResponseFactory;
use App\Models\InsPhDosingDevice;
use App\Models\InsPhDosingLog;
use ModbusTcpClient\Utils\Types;

class InsPhDossingLogPoll extends Command
{
    // Configuration variables
    protected $pollIntervalSeconds = 1;
    protected $modbusTimeoutSeconds = 2;
    protected $modbusPort = 503;
    public $addressWrite  = [
        // Read Input Register (04)
        'current_ph'            => 0,
        '1_hours_ago_ph'        => 10,
        'amount'                => 107,
        
        // Read Holding Register (03)
        'dosing_count'          => 0,
        'setting_formula_1'     => 19,
        'setting_formula_2'     => 20,
        'setting_formula_3'     => 21,
        'setting_ph_to_high'    => 1,
        'setting_ph_high_min'   => 2,
        'setting_ph_high_max'   => 4,
        'setting_ph_middle_min' => 3,
        'setting_ph_middle_max' => 5,
        'setting_formula_1_amount'     => 101,
        'setting_formula_2_amount'     => 103,
        'setting_formula_3_amount'     => 105,
        
        // Coils
        'reset'                 => 13,
    ];
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:ins-ph-dossing-log-poll {--v : Verbose output} {--d : Debug output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll PH Dossing log data from Modbus servers and track incremental counts';

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
        $devices = InsPhDosingDevice::active()->get();
        if ($devices->isEmpty()) {
            $this->error('✗ No active PH Dossing devices found');
            return 1;
        }
        
        $this->info('✓ InsPhDossingLogPoll started - monitoring ' . count($devices) . ' devices');
        if ($this->option('v')) {
            $this->comment('Devices:');
            foreach ($devices as $device) {
                $this->comment("  → {$device->name} ({$device->ip_address})");
            }
        }

        // Continuous polling loop for real-time monitoring
        while (true) {
            $cycleStartTime = microtime(true);
            $cycleReadings = 0;
            $cycleErrors = 0;

            foreach ($devices as $device) {
                if ($this->option('v')) {
                    $this->comment("→ Polling {$device->name} ({$device->ip_address})");
                }

                try {
                    $readings = $this->pollDevice($device);
                    $cycleReadings += $readings;
                    
                    if ($readings > 0) {
                        $this->info("✓ Polling {$device->name} completed - {$readings} new readings saved");
                    } else {
                        if ($this->option('d')) {
                            $this->info("→ Polling {$device->name} completed - no new readings");
                        }
                    }
                    
                    $this->updateDeviceStats($device->name, true);
                } catch (\Throwable $th) {
                    $this->error("✗ Error polling {$device->name}: " . $th->getMessage());
                    $cycleErrors++;
                    $this->updateDeviceStats($device->name, false);
                }
            }

            $this->totalReadings += $cycleReadings;
            $this->totalErrors += $cycleErrors;

            if ($this->option('v') && ($cycleReadings > 0 || $cycleErrors > 0)) {
                $cycleTime = microtime(true) - $cycleStartTime;
                $this->info("Cycle #{$this->pollCycleCount}: {$cycleReadings} new readings saved, {$cycleErrors} errors, " .
                            number_format($cycleTime * 1000, 2) . "ms");
            }

            // Wait before next poll cycle
            sleep($this->pollIntervalSeconds);
            $this->pollCycleCount++;

            // Periodic memory cleanup
            if ($this->pollCycleCount % $this->memoryCleanupInterval === 0) {
                $this->cleanupMemory();
            }
        }
    }

    private function pollDevice(InsPhDosingDevice $device)
    {
        $unit_id = 1; // Standard Modbus unit ID
        $readingsCount = 0;
        
        try {
            // Read Input Register (04) - Get current dosing_amount from HMI
            $dosing_amount = $this->readInputFunction($device, $this->addressWrite['amount'], 'dosing_amount');
            $dosing_amount = (int) $dosing_amount;

            // get formula dossing_amount from HMI
            $formula_1_amount = $this->readInputFunction($device, $this->addressWrite['setting_formula_1_amount'], 'setting_formula_1_amount');
            $formula_2_amount = $this->readInputFunction($device, $this->addressWrite['setting_formula_2_amount'], 'setting_formula_2_amount');
            $formula_3_amount = $this->readInputFunction($device, $this->addressWrite['setting_formula_3_amount'], 'setting_formula_3_amount');

            $data_dosing = [
                'formula_1_amount' => (int) $formula_1_amount,
                'formula_2_amount' => (int) $formula_2_amount,
                'formula_3_amount' => (int) $formula_3_amount,
                'total_amount' => $formula_1_amount + $formula_2_amount + $formula_3_amount,
            ];

            // Get the last dosing_amount from database for this device
            $lastLog = InsPhDosingLog::where('device_id', $device->id)
                ->orderBy('created_at', 'desc')
                ->first();
            
            $lastDosingAmount = $lastLog ? $lastLog->dosing_amount : null;
            
            // Only save if dosing_amount has changed
            if ($lastDosingAmount === null || $dosing_amount !== $lastDosingAmount) {
                $ph_dosing_log = new InsPhDosingLog();
                $ph_dosing_log->device_id  = $device->id;
                $ph_dosing_log->dosing_amount = $dosing_amount;
                $ph_dosing_log->data_dosing = $data_dosing;
                $ph_dosing_log->save();
                $readingsCount++;
                
                if ($this->option('v')) {
                    $this->line("  → Dosing amount changed: {$lastDosingAmount} → {$dosing_amount}");
                }
            } else {
                if ($this->option('d')) {
                    $this->line("  → Dosing amount unchanged: {$dosing_amount}");
                }
            }
            
            return $readingsCount;
        } catch (\Throwable $th) {
            $this->error("✗ Error polling {$device->name}: " . $th->getMessage());
            return 0;
        }
    }
    
    // READ INPUT FUNCTION
    private function readInputFunction(InsPhDosingDevice $device, $address, $name)
    {
        $unit_id = 1;
        $request = ReadRegistersBuilder::newReadInputRegisters('tcp://'.$device->ip_address.':503', $unit_id)
            ->int16($address, $name)
            ->build();
        $response = (new NonBlockingClient(['readTimeoutSec' => $this->modbusTimeoutSeconds]))
            ->sendRequests($request)->getData();
        return $response[$name];
    }

    /**
     * Clean up memory by removing old entries and forcing garbage collection
     */
    public function cleanupMemory()
    {
        // Limit the lastCumulativeValues array size by keeping only active lines
        $activePlants = InsPhDosingDevice::active()->get()->flatMap(function ($device) {
            return $device->plant;
        })->unique()->toArray();

        // Remove entries for lines that are no longer active
        $this->lastCumulativeValues = array_intersect_key(
            $this->lastCumulativeValues,
            array_flip($activePlants)
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
