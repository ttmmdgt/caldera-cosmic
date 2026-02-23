<?php

namespace App\Console\Commands;

use App\Models\InsPhDosingDevice;
use Illuminate\Console\Command;
use ModbusTcpClient\Composer\Write\WriteRegistersBuilder;
use ModbusTcpClient\Network\NonBlockingClient;
use ModbusTcpClient\Network\BinaryStreamConnection;
use ModbusTcpClient\Composer\Write\WriteCoilsBuilder;
use ModbusTcpClient\Packet\ModbusFunction\WriteMultipleRegistersRequest;
use ModbusTcpClient\Utils\Types;

class InsPhDossingReset extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:ins-ph-dossing-reset {--v : Verbose output} {--d : Debug output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send reset signal to all PH Dossing devices (writes 1 to reset addresses)';


    public function handle()
    {
        // Get all active devices
        $devices = InsPhDosingDevice::active()->get();

        if ($devices->isEmpty()) {
            $this->error('✗ No active PH Dossing devices found');
            return 1;
        }

        $this->info('✓ InsPhDossingReset started - resetting ' . count($devices) . ' devices');
        $successCount = 0;
        $errorCount = 0;

        foreach ($devices as $device) {
            if ($this->option('v')) {
                $this->comment("→ Resetting {$device->name} ({$device->ip_address})");
            }
            try {
                $this->bruteForceReset($device);
                $successCount++;
            } catch (\Throwable $th) {
                $this->error("✗ Error resetting {$device->name} ({$device->ip_address}): " . $th->getMessage() . " on line " . $th->getLine());
                $errorCount++;
            }
        }

        $this->info("✓ Reset completed. Success: {$successCount}, Errors: {$errorCount}");
        
        return $errorCount > 0 ? 1 : 0;

    }

    /**
     * Reset a single device by writing 1 to all reset addresses
     */
    private function resetDevice(InsPhDosingDevice $device)
    {
        $unit_id = 1; // Standard Modbus unit ID
        $resetAddr = 13;
        try {
            // Build Modbus coils
            $request = WriteCoilsBuilder::newWriteMultipleCoils(
                'tcp://' . $device->ip_address . ':503', 
                $unit_id,
                1
            )
            ->coil($resetAddr,1) // <-- Write a single 'true' (1) value
            ->build();

            // Execute Modbus write request
            $response = (new NonBlockingClient(['readTimeoutSec' => 2]))->sendRequests($request);
            if ($this->option('v')) {
                $this->info("  ✓ Reset signal sent to line {$device->line} at address {$resetAddr}");
            }

        } catch (\Exception $e) {
            $this->error("    ✗ Error resetting line {$device->line} at address {$resetAddr}: " . $e->getMessage() . "\n" . $e->getLine());
            throw $e; // Re-throw to be caught by parent try-catch
        }
    }

    private function bruteForceReset(InsPhDosingDevice $device, int $attempts = 10)
    {
        $unit_id = 1;
        $resetAddr = 13;
        $successCount = 0;
        $lastException = null;

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $request = WriteCoilsBuilder::newWriteMultipleCoils(
                    'tcp://' . $device->ip_address . ':503',
                    $unit_id,
                    1
                )
                ->coil($resetAddr, 1)
                ->build();

                (new NonBlockingClient(['readTimeoutSec' => 2]))->sendRequests($request);
                $successCount++;

                if ($this->option('d')) {
                    $this->info("    ✓ Brute reset {$i}/{$attempts} sent to line {$device->line}");
                }
            } catch (\Exception $e) {
                $lastException = $e;
                if ($this->option('d')) {
                    $this->warn("    ⟳ Brute reset {$i}/{$attempts} failed for line {$device->line}: " . $e->getMessage());
                }
            }

            if ($i < $attempts) {
                usleep(200_000); // 200ms delay between attempts
            }
        }

        if ($this->option('v')) {
            $this->info("  ✓ Brute force reset done for line {$device->line}: {$successCount}/{$attempts} succeeded");
        }

        if ($successCount === 0 && $lastException) {
            throw $lastException;
        }
    }
}
