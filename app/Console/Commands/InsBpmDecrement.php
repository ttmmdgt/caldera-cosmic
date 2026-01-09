<?php

namespace App\Console\Commands;

use App\Models\InsBpmDevice;
use App\Models\InsBpmCount;
use Illuminate\Console\Command;

class InsBpmDecrement extends Command
{
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
        
        foreach ($device->config['list_mechine'] as $machineConfig) {
            $machineName = $machineConfig['name'];
            
            // Skip if not machine 1, 2, or 3
            if (!in_array($machineName, $targetMachines)) {
                if ($this->option('d')) {
                    $this->line("  → Skipping machine {$machineName} (not in target list)");
                }
                continue;
            }
            
            $this->decrementMachine($device, $machineName, $today);
        }
    }

    /**
     * Decrement a specific machine's cumulative count
     */
    private function decrementMachine(InsBpmDevice $device, $machineName, $today)
    {
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
    }
}
