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
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = 500;

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
     * Retry a callback up to MAX_RETRIES times with a delay between attempts.
     */
    private function retry(callable $callback, string $operationLabel): void
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $callback();
                return;
            } catch (\Exception $e) {
                $lastException = $e;

                if ($this->option('v')) {
                    $this->warn("    ⟳ {$operationLabel} failed (attempt {$attempt}/" . self::MAX_RETRIES . "): " . $e->getMessage());
                }

                if ($attempt < self::MAX_RETRIES) {
                    usleep(self::RETRY_DELAY_MS * 1000);
                }
            }
        }

        throw $lastException;
    }

    /**
     * Reset a single device by writing 1 to all reset addresses
     */
    private function resetDevice(InsBpmDevice $device)
    {
        $unit_id = 1; // Standard Modbus unit ID
        $resetAddr = $device->config['addr_reset'] ?? null;

        $this->retry(function () use ($device, $unit_id, $resetAddr) {
            $request = WriteCoilsBuilder::newWriteMultipleCoils(
                'tcp://' . $device->ip_address . ':503',
                $unit_id,
                1
            )
            ->coil($resetAddr, 1)
            ->build();

            (new NonBlockingClient(['readTimeoutSec' => 2]))->sendRequests($request);
        }, "Reset {$device->name} line {$device->line} addr {$resetAddr}");

        if ($this->option('v')) {
            $this->info("  ✓ Reset signal sent to line {$device->line} at address {$resetAddr}");
        }
    }
}
