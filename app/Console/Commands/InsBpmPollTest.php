<?php

namespace App\Console\Commands;

use App\Models\InsBpmDevice;
use App\Models\InsBpmCount;
use Carbon\Carbon;
use Illuminate\Console\Command;
use ModbusTcpClient\Composer\Read\ReadRegistersBuilder;
use ModbusTcpClient\Network\NonBlockingClient;

class InsBpmPollTest extends Command
{
    // Configuration variables
    private const POLL_INTERVAL_SECONDS = 1;
    private const MODBUS_TIMEOUT_SECONDS = 2;
    private const MODBUS_PORT = 503;
    private const MODBUS_UNIT_ID = 1;
    
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:ins-bpm-poll-test {--v : Verbose output} {--d : Debug output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll BPM (Back part mold emergency stop) counter data from Modbus servers and track incremental counts';

    /**
     * Read a Modbus counter value from the device
     */
    private function readModbusCounter(string $ipAddress, int $address, string $counterName): int
    {
        $request = ReadRegistersBuilder::newReadInputRegisters(
            "tcp://{$ipAddress}:" . self::MODBUS_PORT,
            self::MODBUS_UNIT_ID
        )
        ->int16($address, $counterName)
        ->build();
        
        $response = (new NonBlockingClient(['readTimeoutSec' => self::MODBUS_TIMEOUT_SECONDS]))
            ->sendRequests($request)
            ->getData();
        
        return abs($response[$counterName]);
    }

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

        // For each device, poll once for testing
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

    private function pollDevice(InsBpmDevice $device): int
    {
        $readingsCount = 0;
        foreach ($device->config['list_mechine'] as $machineConfig) {
            try {
                $readings = $this->pollMachine($device, $machineConfig);
                $readingsCount++;
            } catch (\Exception $e) {
                $this->error("    ✗ Error reading machine {$machineConfig['name']}: {$e->getMessage()} at line {$e->getLine()}");
            }
        }

        return $readingsCount;
    }

    /**
     * Poll a single machine for Hot and Cold counter values
     */
    private function pollMachine(InsBpmDevice $device, array $machineConfig): void
    {
        $machineName = $machineConfig['name'];
        $hotAddress  = $machineConfig['addr_hot'];
        $coldAddress = $machineConfig['addr_cold'];
        $line = $machineConfig['line'] ?? $device->line;
        $this->debugLog("  Polling machine {$machineName} at addresses hot: {$hotAddress}, cold: {$coldAddress}");
        
        // Read counter values from Modbus
        $counterHot = $this->readModbusCounter($device->ip_address, $hotAddress, 'counter_hot');
        $counterCold = $this->readModbusCounter($device->ip_address, $coldAddress, 'counter_cold');
        $this->debugLog("    Read values - Hot: {$counterHot}, Cold: {$counterCold}");
        // Process and save to database
        $this->processCondition($device, $line, $machineName, 'Hot', $counterHot);
        $this->processCondition($device, $line, $machineName, 'Cold', $counterCold);
    }

    /**
     * Normalize line name for consistent storage
     */
    private function normalizeName(string $name): string
    {
        return strtoupper(trim($name));
    }

    /**
     * Log debug message if debug mode is enabled
     */
    private function debugLog(string $message): void
    {
        if ($this->option('d')) {
            $this->line($message);
        }
    }

    /**
     * Get the latest counter record from database for today
     */
    private function getLatestRecord(InsBpmDevice $device, string $line, string $machineName, string $condition): ?InsBpmCount
    {
        return InsBpmCount::where('plant', $device->name)
            ->where('line', $this->normalizeName($line))
            ->where('machine', $this->normalizeName($machineName))
            ->where('condition', $condition)
            ->whereDate('created_at', Carbon::today())
            ->latest('created_at')
            ->first();
    }

    /**
     * Save counter reading to database
     */
    private function saveCounterReading(
        InsBpmDevice $device,
        string $line,
        string $machineName,
        string $condition,
        int $incremental,
        int $cumulative
    ): void {
        InsBpmCount::create([
            'plant' => $device->name,
            'line' => $line,
            'machine' => $machineName,
            'condition' => $condition,
            'incremental' => $incremental,
            'cumulative' => $cumulative,
        ]);
    }

    /**
     * Process a single condition (Hot or Cold) and save if changed
     */
    private function processCondition(InsBpmDevice $device, $line, string $machineName, string $condition, int $currentCumulative): int
    {
        $key = "{$machineName}_{$condition}";
        
        // Get latest record from database to compare
        $latestRecord = $this->getLatestRecord($device, $line, $machineName, $condition);
        
        // If cumulative value from HMI is same as latest DB record, don't save
        if ($latestRecord && (int)$latestRecord->cumulative === (int)$currentCumulative) {
            $this->debugLog("    → No change for {$key} - DB cumulative {$latestRecord->cumulative} = HMI {$currentCumulative} - skipping save");
            return 0;
        }
        
        // Calculate increment based on previous value
        $previousCumulative = $latestRecord ? $latestRecord->cumulative : 0;
        $increment = $currentCumulative - $previousCumulative;
        
        // Save if: increment is positive OR this is the very first record (initialize with 0 or any value)
        if ($increment > 0 || !$latestRecord) {
            $incremental = ($increment > 0) ? $increment : 0; // First record gets incremental = 0 for initialization
            
            $this->saveCounterReading($device, $line, $machineName, $condition, $incremental, $currentCumulative);
            $this->debugLog("    ✓ Saved {$condition}: increment {$incremental}, cumulative {$currentCumulative}");
            
            return 1;
        }
        
        // Handle negative or zero increment (counter reset or decrease)
        $this->debugLog("    ⚠ Negative or zero increment ({$increment}) for {$key} - skipping save");
        
        return 0;
    }

}
