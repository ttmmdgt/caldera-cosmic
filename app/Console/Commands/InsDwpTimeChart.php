<?php

namespace App\Console\Commands;

use App\Models\InsDwpDevice;
use Illuminate\Console\Command;
use ModbusTcpClient\Composer\Write\WriteRegistersBuilder;
use ModbusTcpClient\Network\NonBlockingClient;
use ModbusTcpClient\Composer\Write\WriteCoilsBuilder;
use Carbon\Carbon;
use ModbusTcpClient\Network\BinaryStreamConnection;
use ModbusTcpClient\Packet\ModbusFunction\WriteSingleRegisterRequest;
class InsDwpTimeChart extends Command
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
    protected $signature = 'app:ins-dwp-time-chart {--v : Verbose output} {--d : Debug output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send total alarm signal to all DWP devices (writes 1 to reset addresses)';

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

        $this->info('✓ InsDwpTimeChart started - sending total alarm signal to ' . count($devices) . ' devices');

        $successCount = 0;
        $errorCount = 0;

        foreach ($devices as $device) {
            if ($this->option('v')) {
                $this->comment("→ Resetting {$device->name} ({$device->ip_address})");
            }

            try {
                $this->sendAlarmCount($device);
                $successCount++;
            } catch (\Throwable $th) {
                $this->error("✗ Error resetting {$device->name} ({$device->ip_address}): " . $th->getMessage());
                $errorCount++;
            }
        }

        $this->info("✓ Reset completed. Success: {$successCount}, Errors: {$errorCount}");
        
        return $errorCount > 0 ? 1 : 0;
    }

    private function sendAlarmCount($device)
    {
        $addrDwpAlarm = $device['config'][0]['dwp_alarm'];
        // today
        $this->start_at = Carbon::now()->startOfDay();
        $this->end_at = Carbon::now()->endOfDay();
        // GET LONG DURATION DATA from database
        // config address - hours 8 to 14, skipping 12 (rest time)
        $workingHours = [8, 9, 10, 11, 13, 14, 15, 16, 17]; // Skip hour 12 for rest time
        
        $arrayChartDate = [
            7110,
            7111,
            7112,
            7113,
            7114,
            7115,
            7116,
            7117,
            7118,
        ];
        
        try {
            $connection = BinaryStreamConnection::getBuilder()
                ->setHost($device->ip_address)
                ->setPort($this->modbusPort)
                ->build();

            $connection->connect();

            foreach ($arrayChartDate as $index => $address) {
                $hourValue = $workingHours[$index];

                if ($this->option('d')) {
                    $this->info("    → Sending hour {$hourValue} to address {$address} on {$device->name} ({$device->ip_address})");
                }
                
                $packet = new WriteSingleRegisterRequest(
                    $address,       // Register address
                    $hourValue,     // Value (hour)
                    1               // Unit ID
                );
                
                $connection->send($packet);
                
                // Add small delay to ensure HMI processes each write
                usleep(100000); // 100ms delay between writes
                
                if ($this->option('v')) {
                    $this->info("    ✓ Successfully sent hour {$hourValue} to address {$address}");
                }
            }
            
            $connection->close();
        } catch (\Exception $e) {
            $this->error("    ✗ Error sending hours to {$device->name} ({$device->ip_address}): " . $e->getMessage());
            return false;
        }
        
        return true;
    }
}
