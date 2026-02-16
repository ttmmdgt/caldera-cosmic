<?php

namespace App\Console\Commands;

use App\Models\InsBpmDevice;
use App\Models\InsBpmPower;
use Illuminate\Console\Command;
use ModbusTcpClient\Composer\Read\ReadRegistersBuilder;
use ModbusTcpClient\Network\NonBlockingClient;

class InsBpmPollPower extends Command
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
    protected $signature = 'app:ins-bpm-poll-power {--v : Verbose output} {--d : Debug output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll BPM (Back part mold emergency) power data from Modbus servers and track incremental power';

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
        $this->info('✓ InsBpmPollPower started - monitoring ' . count($devices) . ' devices');
        if ($this->option('v')) {
            $this->comment('Devices:');
            foreach ($devices as $device) {
                $lines = implode(', ', $device->getLines());
                $this->comment("  → {$device->name} ({$device->ip_address}) - Lines: {$lines}");
            }
        }

        // For each device, poll once
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

    /**
     * Poll a single device for power values
     */
    private function pollDevice(InsBpmDevice $device): int
    {
        $readingsCount = 0;
        foreach ($device->config['list_mechine'] as $machineConfig) {
            try {
                $this->pollMachine($device, $machineConfig);
                $readingsCount++;
            } catch (\Exception $e) {
                $this->error("    ✗ Error reading machine {$machineConfig['name']}: {$e->getMessage()} at line {$e->getLine()}");
            }
        }

        return $readingsCount;
    }

    /**
     * Poll a single machine for power values
     */
    private function pollMachine(InsBpmDevice $device, array $machineConfig): void
    {
        $machineName   = $machineConfig['name'];
        $powerAddress  = $machineConfig['addr_power'] ?? null;
        if (!$powerAddress) {
            $this->error("    ✗ Error: Power address not found for machine {$machineName}");
            return;
        }
        $this->debugLog("  Polling machine {$machineName} at addresses power: {$powerAddress}");
        $power = $this->readModbusCounter($device->ip_address, $powerAddress, 'power');
        $this->debugLog("    Read values - Power: {$power}");
        // Process and save to database (PLC is counting, we just record when it changes)
        $this->processPowerCounter($device, $machineName, $power);
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
     * Get the latest power record from database for this device and machine
     */
    private function getLatestRecord(InsBpmDevice $device, string $machineName): ?InsBpmPower
    {
        return InsBpmPower::where('device_id', $device->id)
            ->where('machine', $machineName)
            ->latest('created_at')
            ->first();
    }

    /**
     * Process power counter from PLC and save if changed
     */
    private function processPowerCounter(InsBpmDevice $device, string $machineName, int $currentPower): int
    {
        $latestRecord = $this->getLatestRecord($device, $machineName);

        // First record ever - initialize with current power value
        if (!$latestRecord) {
            InsBpmPower::create([
                'device_id'   => $device->id,
                'machine'     => $machineName,
                'condition'   => 'on',  // Default condition
                'incremental' => 0,
                'cumulative'  => $currentPower,
            ]);
            $this->debugLog("    ✓ Initialized {$machineName}: cumulative {$currentPower}");
            return 1;
        }

        $increment = $currentPower - $latestRecord->cumulative;

        // Save only if increment is positive (counter increased)
        if ($increment > 0) {
            InsBpmPower::create([
                'device_id'   => $device->id,
                'machine'     => $machineName,
                'condition'   => 'on',  // Default condition
                'incremental' => $increment,
                'cumulative'  => $currentPower,
            ]);
            $this->debugLog("    ✓ Saved {$machineName}: increment {$increment}, cumulative {$currentPower}");
            return 1;
        }

        // Handle negative or zero increment (counter reset or no change)
        if ($increment < 0) {
            $this->debugLog("    ⚠ Negative increment ({$increment}) for {$machineName} - possible PLC reset, skipping save");
        } else {
            $this->debugLog("    → No change for {$machineName} (still {$currentPower})");
        }
        return 0;
    }
}
