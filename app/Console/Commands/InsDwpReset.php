<?php

namespace App\Console\Commands;

use App\Models\InsDwpDevice;
use Illuminate\Console\Command;
use ModbusTcpClient\Composer\Write\WriteRegistersBuilder;
use ModbusTcpClient\Composer\Write\WriteCoilsBuilder;
use ModbusTcpClient\Composer\Read\ReadRegistersBuilder;
use ModbusTcpClient\Network\NonBlockingClient;
use ModbusTcpClient\Network\BinaryStreamConnection;
use ModbusTcpClient\Packet\ModbusFunction\WriteSingleCoilRequest;
use ModbusTcpClient\Packet\ModbusFunction\WriteSingleRegisterRequest;
use ModbusTcpClient\Packet\ResponseFactory;
use ModbusTcpClient\Utils\Types;

class InsDwpReset extends Command
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
    protected $signature = 'app:ins-dwp-reset {--v : Verbose output} {--d : Debug output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send reset signal to all DWP devices (writes 1 to reset addresses)';

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

        $this->info('✓ InsDwpReset started - resetting ' . count($devices) . ' devices');

        $successCount = 0;
        $errorCount = 0;

        foreach ($devices as $device) {
            if ($this->option('v')) {
                $this->comment("→ Resetting {$device->name} ({$device->ip_address})");
            }

            try {
                $this->resetDevice($device);
                $this->sendResetDurationToDevice($device);
                $successCount++;
            } catch (\Throwable $th) {
                $this->error("✗ Error resetting {$device->name} ({$device->ip_address}): " . $th->getMessage());
                $errorCount++;
            }
        }

        $this->info("✓ Reset completed. Success: {$successCount}, Errors: {$errorCount}");
        
        return $errorCount > 0 ? 1 : 0;
    }

    /**
     * Reset a single device by writing 1 to all reset addresses
     */
    private function resetDevice(InsDwpDevice $device)
    {
        $unit_id = 1; // Standard Modbus unit ID
        
        foreach ($device->config as $lineConfig) {
            $line = strtoupper(trim($lineConfig['line']));
            $resetAddr = $lineConfig['dwp_alarm']['addr_reset'];
            if ($this->option('d')) {
                $this->line("  Resetting line {$line} at address {$resetAddr}");
            }

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
                    $this->info("  ✓ Reset signal sent to line {$line}");
                }

            } catch (\Exception $e) {
                $this->error("    ✗ Error resetting line {$line}: " . $e->getMessage());
                throw $e; // Re-throw to be caught by parent try-catch
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
            ->setHost("172.70.87.35")
            ->setPort(503)
            ->build();

        $packet = new WriteSingleRegisterRequest(
            609,    // Register address
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
}
