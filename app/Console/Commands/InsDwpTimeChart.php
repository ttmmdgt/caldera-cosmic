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
    private const CONTROL_ADDRESS = 7000;
    private const COUNT_ADDRESS = 7001;
    private const DATA_START_ADDRESS = 7002;
    private const CHART_LENGTH = 10; // 7-11 and 13-17
    private const WORKING_HOURS = [7, 8, 9, 10, 11, 13, 14, 15, 16, 17];

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
                if ($this->sendAlarmCount($device)) {
                    $successCount++;
                } else {
                    $errorCount++;
                }
            } catch (\Throwable $th) {
                $this->error("✗ Error processing {$device->name} ({$device->ip_address}): " . $th->getMessage());
                $errorCount++;
            }
        }

        $this->info("✓ Chart data send completed. Success: {$successCount}, Errors: {$errorCount}");
        
        return $errorCount > 0 ? 1 : 0;
    }

    private function sendAlarmCount(InsDwpDevice $device): bool
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

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn("    🔍 DRY RUN MODE - No data will be sent");
        }

        $connection = null;
        $dayStart = Carbon::now()->startOfDay();

        try {
            if (!$dryRun) {
                $connection = BinaryStreamConnection::getBuilder()
                    ->setHost($device->ip_address)
                    ->setPort($this->modbusPort)
                    ->setConnectTimeoutSec(5)
                    ->setReadTimeoutSec($this->modbusTimeoutSeconds)
                    ->setWriteTimeoutSec($this->modbusTimeoutSeconds)
                    ->build();

                $connection->connect();
            }

            // Send data point count first (RW-7001)
            if (!$dryRun) {
                $modbusCountAddress = self::COUNT_ADDRESS - 1;
                $this->info("    → Sending data count (" . self::CHART_LENGTH . ") to PLC address " . self::COUNT_ADDRESS . " (Modbus: {$modbusCountAddress})");
                $countPacket = new WriteSingleRegisterRequest($modbusCountAddress, self::CHART_LENGTH, 1);
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
                    $hourlyData = [1, 21, 3, 1, 0, 0, 0, 0, 0, 0];
                    if ($this->option('v')) {
                        $this->info("    → Using TEST data for line {$line}");
                    }
                } else {
                    $hourlyData = $this->buildHourlyData($line, $dayStart);
                }
                
                $this->info("    → Line {$line} hourly data: " . implode(', ', $hourlyData));
                
                // Calculate data address for this line
                $lineDataAddress = self::DATA_START_ADDRESS + ($lineIndex * self::CHART_LENGTH);
                $modbusDataAddress = $lineDataAddress - 1;
                
                $this->info("    → Sending chart data to PLC addresses {$lineDataAddress}-" . ($lineDataAddress + count($hourlyData) - 1) . " (Modbus: {$modbusDataAddress})");
                
                // Convert values to Int16 registers
                $registers = array_map(function ($value) {
                    return Types::toInt16((int) $value);
                }, $hourlyData);
                
                if ($dryRun) {
                    $this->warn("    📤 Would send to {$device->ip_address}:{$this->modbusPort}");
                } else {
                    // Write all data at once using WriteMultipleRegisters
                    $packet = new WriteMultipleRegistersRequest($modbusDataAddress, $registers, 1);
                    $connection->send($packet);
                }
                
                $this->info("    ✓ Successfully sent " . count($hourlyData) . " data points for line {$line}");
            }
            
            // After ALL lines data is written, send count and trigger refresh
            if (!$dryRun) {
                // Small delay before trigger
                usleep(100000); // 100ms delay
                
                // Trigger chart update by writing 1 to control address RW-7000
                $modbusControlAddress = self::CONTROL_ADDRESS - 1;
                
                $this->info("    → Triggering chart update at PLC address " . self::CONTROL_ADDRESS . " (Modbus: {$modbusControlAddress})");
                $triggerPacket = new WriteSingleRegisterRequest($modbusControlAddress, 1, 1);
                $connection->send($triggerPacket);
                
                $this->info("    ✓ Chart refresh triggered successfully");
            }
        } catch (\Throwable $e) {
            $this->error("    ✗ Error sending chart data to {$device->name} ({$device->ip_address}): " . $e->getMessage());
            if ($this->option('d')) {
                $this->error("    Stack trace: " . $e->getTraceAsString());
            }
            return false;
        } finally {
            if ($connection !== null) {
                $connection->close();
            }
        }
        
        return true;
    }

    private function buildHourlyData(string $line, Carbon $dayStart): array
    {
        $hourlyData = [];

        foreach (self::WORKING_HOURS as $hour) {
            $hourStart = $dayStart->copy()->addHours($hour);
            $hourEnd = $hourStart->copy()->addHour();

            // Use [start, end) to avoid counting the same record on hour boundaries.
            $alarmCount = InsDwpTimeAlarmCount::where('line', $line)
                ->where('created_at', '>=', $hourStart)
                ->where('created_at', '<', $hourEnd)
                ->sum('incremental');

            $hourlyData[] = (int) $alarmCount;
        }

        if (count($hourlyData) < self::CHART_LENGTH) {
            return array_pad($hourlyData, self::CHART_LENGTH, 0);
        }

        if (count($hourlyData) > self::CHART_LENGTH) {
            return array_slice($hourlyData, 0, self::CHART_LENGTH);
        }

        return $hourlyData;
    }
}
