<?php

namespace App\Console\Commands;

use App\Models\InsBpmDevice;
use App\Models\InsBpmCount;
use Illuminate\Console\Command;
use ModbusTcpClient\Composer\Read\ReadRegistersBuilder;
use ModbusTcpClient\Network\NonBlockingClient;
use ModbusTcpClient\Network\BinaryStreamConnection;
use ModbusTcpClient\Packet\ModbusFunction\WriteMultipleRegistersRequest;
use ModbusTcpClient\Utils\Types;

class InsBpmDecrement extends Command
{
    public $addressWrite = [
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
    protected $signature = 'app:ins-bpm-decrement {--v : Verbose output} {--d : Debug output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Decrement today\'s last cumulative count for machines 1, 2, 3 by 1';

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

        $this->info('✓ InsBpmDecrement started - processing ' . count($devices) . ' devices');
        $successCount = 0;
        $errorCount = 0;
        $today = now()->startOfDay();

        foreach ($devices as $device) {
            if ($this->option('v')) {
                $this->comment("→ Processing {$device->name} ({$device->ip_address})");
            }

            try {
                $this->decrementDevice($device, $today);
                $successCount++;
            } catch (\Throwable $th) {
                $this->error("✗ Error processing {$device->name} ({$device->ip_address}): " . $th->getMessage() . " on line " . $th->getLine());
                $errorCount++;
            }
        }

        $this->info("✓ Decrement completed. Success: {$successCount}, Errors: {$errorCount}");
        
        return $errorCount > 0 ? 1 : 0;
    }

    /**
     * Decrement cumulative count for machines 1, 2, 3 based on today's last data
     */
    private function decrementDevice(InsBpmDevice $device, $today)
    {
        if (!isset($device->config['list_mechine'])) {
            if ($this->option('v')) {
                $this->warn("  → No machines configured for {$device->name}");
            }
            return;
        }

        // Filter only machines 1, 2, 3
        $targetMachines = ['1', '2', '3'];
        $hmiUpdates = []; // Collect HMI updates
        
        foreach ($device->config['list_mechine'] as $machineConfig) {
            $machineName = $machineConfig['name'];
            $line = $machineConfig['line'] ?? $device->line;
            
            // Skip if not machine 1, 2, or 3
            if (!in_array($machineName, $targetMachines)) {
                if ($this->option('d')) {
                    $this->line("  → Skipping machine {$machineName} (not in target list)");
                }
                continue;
            }
            
            $updates = $this->decrementMachine($device, $machineName, $line, $today);
            $hmiUpdates = array_merge($hmiUpdates, $updates);
        }

        // Write all HMI updates in one call
        if (!empty($hmiUpdates)) {
            $this->pushToHmi($device, $hmiUpdates);
        }
    }

    /**
     * Decrement a specific machine's cumulative count
     */
    private function decrementMachine(InsBpmDevice $device, $machineName, $line, $today)
    {
        $hmiUpdates = [];
        
        // Process both Hot and Cold conditions
        foreach (['Hot', 'Cold'] as $condition) {
            try {
                // Get today's last record for this machine and condition
                $lastRecord = InsBpmCount::where('machine', strtoupper($machineName))
                    ->where('condition', $condition)
                    ->where('created_at', '>=', $today)
                    ->latest('created_at')
                    ->first();

                if (!$lastRecord) {
                    if ($this->option('v')) {
                        $this->line("  → No data today for Machine {$machineName} ({$condition})");
                    }
                    continue;
                }

                $oldCumulative = $lastRecord->cumulative;
                $newCumulative = max(0, $oldCumulative - 1); // Prevent negative values
                
                // Create new record with decremented value
                InsBpmCount::create([
                    'plant' => $lastRecord->plant,
                    'line' => $lastRecord->line,
                    'machine' => $lastRecord->machine,
                    'condition' => $condition,
                    'incremental' => -1, // Negative increment to show decrement
                    'cumulative' => $newCumulative,
                ]);

                // Add to HMI updates
                $hmiUpdates["M{$line}_{$condition}"] = $newCumulative;

                if ($this->option('v')) {
                    $this->info("  ✓ Machine {$machineName} ({$condition}): {$oldCumulative} → {$newCumulative}");
                }

            } catch (\Exception $e) {
                $this->error("  ✗ Error decrementing Machine {$machineName} ({$condition}): " . $e->getMessage());
                if ($this->option('d')) {
                    $this->error("    Line: " . $e->getLine());
                }
            }
        }

        return $hmiUpdates;
    }

    /**
     * Push updates to HMI
     */
    private function pushToHmi(InsBpmDevice $device, array $updates)
    {
        if (empty($updates)) {
            return false;
        }

        $unit_id = 1;
        
        try {
            // Step 1: Read current counter values from addresses 0-7 (only once)
            $requestCounters = ReadRegistersBuilder::newReadInputRegisters(
                'tcp://' . $device->ip_address . ':503',
                $unit_id)
                ->int16(0, 'M1_Hot_counter')
                ->int16(1, 'M1_Cold_counter')
                ->int16(2, 'M2_Hot_counter')
                ->int16(3, 'M2_Cold_counter')
                ->int16(4, 'M3_Hot_counter')
                ->int16(5, 'M3_Cold_counter')
                ->int16(6, 'M4_Hot_counter')
                ->int16(7, 'M4_Cold_counter')
                ->build();

            $responseCounters = (new NonBlockingClient(['readTimeoutSec' => 2]))
                ->sendRequests($requestCounters)->getData();

            if ($this->option('d')) {
                $this->line("    ✓ Read counter values from HMI {$device->ip_address}");
            }

            // Step 2: Prepare values array for all 8 registers (addresses 10-17)
            $values = [
                Types::toRegister($responseCounters['M1_Hot_counter'] ?? 0),
                Types::toRegister($responseCounters['M1_Cold_counter'] ?? 0),
                Types::toRegister($responseCounters['M2_Hot_counter'] ?? 0),
                Types::toRegister($responseCounters['M2_Cold_counter'] ?? 0),
                Types::toRegister($responseCounters['M3_Hot_counter'] ?? 0),
                Types::toRegister($responseCounters['M3_Cold_counter'] ?? 0),
                Types::toRegister($responseCounters['M4_Hot_counter'] ?? 0),
                Types::toRegister($responseCounters['M4_Cold_counter'] ?? 0),
            ];

            // Step 3: Update values based on what changed
            foreach ($updates as $machineKey => $counter) {
                $valueIndex = array_search($machineKey, array_keys($this->addressWrite));
                if ($valueIndex !== false) {
                    $values[$valueIndex] = Types::toRegister($counter);
                    if ($this->option('d')) {
                        $this->line("      Updated {$machineKey} = {$counter}");
                    }
                }
            }

            // Step 4: Write all counters to HMI in one operation
            $connection = BinaryStreamConnection::getBuilder()
                ->setHost($device->ip_address)
                ->setPort(503)
                ->build();
            
            $packet = new WriteMultipleRegistersRequest(
                10, // Starting address
                $values, // Array of 8 values
                $unit_id
            );
            
            $connection->connect();
            $connection->send($packet);
            $connection->close();

            if ($this->option('v')) {
                $this->line("    ✓ Wrote " . count($updates) . " decremented counter(s) to HMI");
            }

            return true;

        } catch (\Exception $e) {
            $this->error("    ✗ Error writing to HMI {$device->ip_address}: " . $e->getMessage());
            return false;
        }
    }
}
