<?php

namespace App\Console\Commands;

use App\Models\InsBpmDevice;
use Illuminate\Console\Command;
use ModbusTcpClient\Composer\Write\WriteRegistersBuilder;
use ModbusTcpClient\Network\NonBlockingClient;
use ModbusTcpClient\Network\BinaryStreamConnection;
use ModbusTcpClient\Composer\Write\WriteCoilsBuilder;
use ModbusTcpClient\Packet\ModbusFunction\WriteMultipleRegistersRequest;
use ModbusTcpClient\Utils\Types;

class InsBpmReset extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:ins-bpm-reset {--v : Verbose output} {--d : Debug output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send reset signal to all BPM devices (writes 1 to reset addresses)';

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

        $this->info('✓ InsBpmReset started - resetting ' . count($devices) . ' devices');
        $successCount = 0;
        $errorCount = 0;

        foreach ($devices as $device) {
            if ($this->option('v')) {
                $this->comment("→ Resetting {$device->name} ({$device->ip_address})");
            }

            try {
                $this->resetDevice($device);
                $this->reinit($device);
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
    private function resetDevice(InsBpmDevice $device)
    {
        $unit_id = 1; // Standard Modbus unit ID
        $resetAddr = $device->config['addr_reset'] ?? null;
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

    private function reinit(InsBpmDevice $device)
    {
        $unit_id = 1; // Standard Modbus unit ID
        
        try {
            // Prepare array of 8 zero values for addresses 10-17
            $values = [
                Types::toRegister(0), // Address 10 - M1_Hot
                Types::toRegister(0), // Address 11 - M1_Cold
                Types::toRegister(0), // Address 12 - M2_Hot
                Types::toRegister(0), // Address 13 - M2_Cold
                Types::toRegister(0), // Address 14 - M3_Hot
                Types::toRegister(0), // Address 15 - M3_Cold
                Types::toRegister(0), // Address 16 - M4_Hot
                Types::toRegister(0), // Address 17 - M4_Cold
            ];

            // Write all registers using WriteMultipleRegistersRequest
            $connection = BinaryStreamConnection::getBuilder()
                ->setHost($device->ip_address)
                ->setPort(503)
                ->build();
            
            $packet = new WriteMultipleRegistersRequest(
                10, // Starting address
                $values, // Array of 8 zero values
                $unit_id
            );
            
            $connection->connect();
            $connection->send($packet);
            $connection->close();

            if ($this->option('v')) {
                $this->info("  ✓ Reinitialized all counters (addresses 10-17) to 0 for {$device->name}");
            }

        } catch (\Exception $e) {
            $this->error("    ✗ Error reinitializing {$device->name}: " . $e->getMessage());
            throw $e; // Re-throw to be caught by parent try-catch
        }
    }
}
