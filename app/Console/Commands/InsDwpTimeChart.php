<?php

namespace App\Console\Commands;

use App\Models\InsDwpDevice;
use Illuminate\Console\Command;
use App\Models\InsDwpTimeAlarmCount;
use Carbon\Carbon;
use ModbusTcpClient\Network\BinaryStreamConnection;
use ModbusTcpClient\Packet\ModbusFunction\WriteMultipleRegistersRequest;
use ModbusTcpClient\Utils\Types;
class InsDwpTimeChart extends Command
{
     // Configuration variables
    protected $pollIntervalSeconds = 1;
    protected $modbusTimeoutSeconds = 2;
    protected $modbusPort = 502; // Standard Modbus TCP port
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
        
        // Working hours (7 AM to 4 PM, skipping 12 PM for rest time)
        $workingHours = [7, 8, 9, 10, 11, 13, 14, 15, 16];
        
        // Starting address for chart data
        $startAddress = 7000;
        
        if ($this->option('dry-run')) {
            $this->warn("    🔍 DRY RUN MODE - No data will be sent");
        }
        
        try {
            if (!$this->option('dry-run')) {
                $connection = BinaryStreamConnection::getBuilder()
                    ->setHost($device->ip_address)
                    ->setPort($this->modbusPort)
                    ->build();

                $connection->connect();
            }

            // Get alarm count data for each line managed by this device
            foreach ($lines as $lineIndex => $line) {
                $this->info("    → Processing line: {$line}");

                // Fetch hourly alarm counts from database
                $hourlyData = [];
                
                if ($this->option('test')) {
                    // Send test data to verify chart is working
                    $hourlyData = [2, 5, 8, 14, 10, 6, 12, 8, 4];
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
                
                // Ensure we have exactly 9 data points (padding with 0 if needed)
                $chartLength = 9;
                if (count($hourlyData) < $chartLength) {
                    $hourlyData = array_pad($hourlyData, $chartLength, 0);
                } elseif (count($hourlyData) > $chartLength) {
                    $hourlyData = array_slice($hourlyData, 0, $chartLength);
                }
                
                $this->info("    → Line {$line} hourly data: " . implode(', ', $hourlyData));
                
                // Calculate address offset for this line (each line gets 9 registers)
                $lineStartAddress = $startAddress + ($lineIndex * $chartLength);
                
                $this->info("    → Sending data to addresses {$lineStartAddress}-" . ($lineStartAddress + count($hourlyData) - 1));
                
                // Convert values to Int16 registers
                $registers = array_map(function ($value) {
                    $intValue = (int) $value;
                    return Types::toInt16($intValue);
                }, $hourlyData);
                
                if ($this->option('dry-run')) {
                    $this->warn("    📤 Would send to {$device->ip_address}:{$this->modbusPort}");
                } else {
                    // Create and send WriteMultipleRegistersRequest
                    $packet = new WriteMultipleRegistersRequest(6999, $registers, 1);
                    $response = $connection->sendAndReceive($packet);
                }
                
                $this->info("    ✓ Successfully sent " . count($hourlyData) . " data points for line {$line}");
            }
            
            if (!$this->option('dry-run')) {
                $connection->close();
            }
        } catch (\Exception $e) {
            $this->error("    ✗ Error sending chart data to {$device->name} ({$device->ip_address}): " . $e->getMessage());
            return false;
        }
        
        return true;
    }
}
