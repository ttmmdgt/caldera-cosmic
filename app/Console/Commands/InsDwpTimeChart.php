<?php

namespace App\Console\Commands;

use App\Models\InsDwpDevice;
use Illuminate\Console\Command;
use App\Models\InsDwpTimeAlarmCount;
use Carbon\Carbon;
use ModbusTcpClient\Network\BinaryStreamConnection;
use ModbusTcpClient\Packet\ModbusFunction\WriteMultipleRegistersRequest;
use ModbusTcpClient\Packet\ModbusFunction\WriteSingleRegisterRequest;
use ModbusTcpClient\Utils\Types;
class InsDwpTimeChart extends Command
{
     // Configuration variables
    protected $pollIntervalSeconds = 1;
    protected $modbusTimeoutSeconds = 2;
    protected $modbusPort = 503; // Standard Modbus TCP port
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:ins-dwp-time-chart {--v : Verbose output} {--d : Debug output} {--test : Send test data} {--dry-run : Show what would be sent without connecting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send hourly alarm count chart data to all DWP devices';

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

        $this->info('✓ InsDwpTimeChart started - sending hourly chart data to ' . count($devices) . ' devices');

        $successCount = 0;
        $errorCount = 0;

        foreach ($devices as $device) {
            $this->comment("→ Processing {$device->name} ({$device->ip_address})");

            try {
                $this->sendAlarmCount($device);
                $successCount++;
            } catch (\Throwable $th) {
                $this->error("✗ Error processing {$device->name} ({$device->ip_address}): " . $th->getMessage());
                $errorCount++;
            }
        }

        $this->info("✓ Chart data send completed. Success: {$successCount}, Errors: {$errorCount}");
        
        return $errorCount > 0 ? 1 : 0;
    }

    private function sendAlarmCount($device)
    {
        // Get lines managed by this device
        $lines = $device->getLines();
        
        $this->info("    → Device has " . count($lines) . " line(s): " . implode(', ', $lines));
        
        if (empty($lines)) {
            if ($this->option('v')) {
                $this->warn("    ⚠ No lines configured for {$device->name}");
            }
            return true;
        }

        // Today's date range
        $startAt = Carbon::now()->startOfDay();
        $endAt = Carbon::now()->endOfDay();
        
        // Working hours (8 AM to 5 PM, skipping 12 PM for rest time)
        $workingHours = [7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17];
        
        // Chart configuration addresses (from HMI config)
        $controlAddress = 7000;  // RW-7000: Control/trigger address
        $countAddress = 7001;    // RW-7001: Number of data points (not used by HMI, but kept for compatibility)
        $dataStartAddress = 7002;  // RW-7002: Chart data starts here (see HMI config)
        
        if ($this->option('dry-run')) {
            $this->warn("    🔍 DRY RUN MODE - No data will be sent");
        }
        
        try {
            if (!$this->option('dry-run')) {
                $connection = BinaryStreamConnection::getBuilder()
                    ->setHost($device->ip_address)
                    ->setPort($this->modbusPort)
                    ->setConnectTimeoutSec(5)
                    ->setReadTimeoutSec($this->modbusTimeoutSeconds)
                    ->setWriteTimeoutSec($this->modbusTimeoutSeconds)
                    ->build();

                $connection->connect();
            }

            // Chart configuration
            $chartLength = 11; // HMI expects 10 data points (see config)
            
            // Send data point count first (RW-7001 = 9)
            if (!$this->option('dry-run')) {
                $modbusCountAddress = $countAddress - 1;
                $this->info("    → Sending data count ({$chartLength}) to PLC address {$countAddress} (Modbus: {$modbusCountAddress})");
                $countPacket = new WriteSingleRegisterRequest($modbusCountAddress, $chartLength, 1);
                $connection->send($countPacket);
                usleep(100000); // 100ms delay
            }
            
            // Get alarm count data for each line managed by this device
            foreach ($lines as $lineIndex => $line) {
                $this->info("    → Processing line: {$line}");

                // Fetch hourly alarm counts from database
                $hourlyData = [];
                
                if ($this->option('test')) {
                    // Send test data to verify chart is working
                    $hourlyData = [1, 21, 3, 1, 0, 0, 0, 0, 0];
                    if ($this->option('v')) {
                        $this->info("    → Using TEST data for line {$line}");
                    }
                } else {
                    // Fetch real data from database
                    foreach ($workingHours as $hour) {
                        $hourStart = Carbon::now()->startOfDay()->addHours($hour);
                        $hourEnd = $hourStart->copy()->addHour();
                        
                        // Get alarm count for this hour
                        $alarmCount = InsDwpTimeAlarmCount::where('line', $line)
                            ->whereBetween('created_at', [$hourStart, $hourEnd])
                            ->sum('incremental');
                        
                        $hourlyData[] = (int) $alarmCount;
                    }
                }
                
                // Ensure we have exactly 8 data points (padding with 0 if needed)
                if (count($hourlyData) < $chartLength) {
                    $hourlyData = array_pad($hourlyData, $chartLength, 0);
                } elseif (count($hourlyData) > $chartLength) {
                    $hourlyData = array_slice($hourlyData, 0, $chartLength);
                }
                
                $this->info("    → Line {$line} hourly data: " . implode(', ', $hourlyData));
                
                // Calculate data address for this line
                $lineDataAddress = $dataStartAddress + ($lineIndex * $chartLength);
                $modbusDataAddress = $lineDataAddress - 1;
                
                $this->info("    → Sending chart data to PLC addresses {$lineDataAddress}-" . ($lineDataAddress + count($hourlyData) - 1) . " (Modbus: {$modbusDataAddress})");
                
                // Convert values to Int16 registers
                $registers = array_map(function ($value) {
                    return Types::toInt16((int) $value);
                }, $hourlyData);
                
                if ($this->option('dry-run')) {
                    $this->warn("    📤 Would send to {$device->ip_address}:{$this->modbusPort}");
                } else {
                    // Write all data at once using WriteMultipleRegisters
                    $packet = new WriteMultipleRegistersRequest($modbusDataAddress, $registers, 1);
                    $connection->send($packet);
                }
                
                $this->info("    ✓ Successfully sent " . count($hourlyData) . " data points for line {$line}");
            }
            
            // After ALL lines data is written, send count and trigger refresh
            if (!$this->option('dry-run')) {
                // Small delay before trigger
                usleep(100000); // 100ms delay
                
                // Trigger chart update by writing 1 to control address RW-7000
                $modbusControlAddress = $controlAddress - 1;
                
                $this->info("    → Triggering chart update at PLC address {$controlAddress} (Modbus: {$modbusControlAddress})");
                $triggerPacket = new WriteSingleRegisterRequest($modbusControlAddress, 1, 1);
                $connection->send($triggerPacket);
                
                $this->info("    ✓ Chart refresh triggered successfully");
            }
            
            if (!$this->option('dry-run')) {
                $connection->close();
            }
        } catch (\Exception $e) {
            $this->error("    ✗ Error sending chart data to {$device->name} ({$device->ip_address}): " . $e->getMessage());
            $this->error("    Stack trace: " . $e->getTraceAsString());
            return false;
        }
        
        return true;
    }
}
