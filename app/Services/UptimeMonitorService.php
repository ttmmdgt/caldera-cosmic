<?php

namespace App\Services;

use App\Models\UptimeLog;
use App\Models\InsDwpCount;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use ModbusTcpClient\Network\NonBlockingClient;
use ModbusTcpClient\Composer\Read\ReadRegistersBuilder;

class UptimeMonitorService
{
    /**
     * Check uptime for a single project
     * Supports both HTTP and Modbus TCP checks
     */
    public function checkProject(
        string $projectName, 
        string $ipAddress, 
        int $timeout = 10,
        string $type = 'http',
        array $modbusConfig = []
    ): array
    {
        $startTime = microtime(true);
        $status    = 'offline';
        $message   = '';
        $duration  = null;
        $isTimeout = false;
        $errorType = null;

        try {
            if ($type === 'dwp') {
                // DWP type: Check connection and data freshness
                $result    = $this->checkDwpStatus($ipAddress, $timeout, $modbusConfig);
                $status    = $result['status'];
                $message   = $result['message'];
                $duration  = $result['duration'];
                $isTimeout = $result['is_timeout'] ?? false;
                $errorType = $result['error_type'] ?? null;
            } elseif ($type === 'modbus') {
                // Modbus TCP Connection Test
                $result    = $this->checkModbusConnection($ipAddress, $timeout, $modbusConfig);
                $status    = $result['status'];
                $message   = $result['message'];
                $duration  = $result['duration'];
                $isTimeout = $result['is_timeout'] ?? false;
                $errorType = $result['error_type'] ?? null;
            } else {
                // HTTP Connection Test (default)
                $response = Http::timeout($timeout)->get($ipAddress);
                
                $duration = round((microtime(true) - $startTime) * 1000); // in milliseconds
                
                if ($response->successful()) {
                    $status  = 'online';
                    $message = 'Project is running normally';
                } elseif ($response->status() >= 500) {
                    $status    = 'offline';
                    $errorType = 'server_error';
                    $message   = 'Server error: ' . $response->status();
                } else {
                    $status    = 'idle';
                    $errorType = 'client_error';
                    $message   = 'Unusual response: ' . $response->status();
                }
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $duration = round((microtime(true) - $startTime) * 1000);
            $status = 'offline';
            
            // Detect timeout vs other connection errors
            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, 'timed out') || 
                str_contains($errorMessage, 'timeout') ||
                str_contains($errorMessage, 'ETIMEDOUT')) {
                $isTimeout = true;
                $errorType = 'timeout';
                $message = 'Request timed out after ' . $timeout . ' seconds';
            } elseif (str_contains($errorMessage, 'Connection refused') || 
                      str_contains($errorMessage, 'ECONNREFUSED')) {
                $errorType = 'connection_refused';
                $message = 'Connection refused: Service not available';
            } elseif (str_contains($errorMessage, 'Could not resolve host') ||
                      str_contains($errorMessage, 'getaddrinfo')) {
                $errorType = 'dns_failure';
                $message = 'DNS resolution failed: Cannot resolve hostname';
            } else {
                $errorType = 'connection_error';
                $message = 'Connection failed: ' . $errorMessage;
            }
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000);
            $status = 'offline';
            $errorType = 'unknown_error';
            $message = 'Error: ' . $e->getMessage();
        }

        // Smart logging: Only save to database if status changed OR if it's a different day
        $lastLog = UptimeLog::where('project_name', $projectName)
            ->orderBy('checked_at', 'desc')
            ->first();
        
        $previousStatus = $lastLog ? $lastLog->status : null;
        $statusChanged = $previousStatus !== $status;
        
        // Check if last log was on a different day
        $isDifferentDay = $lastLog 
            ? !Carbon::now()->isSameDay($lastLog->checked_at) 
            : false;
        
        $shouldLog = $statusChanged || $lastLog === null || $isDifferentDay; // Log if first time, status changed, or different day
        
        $logId = null;
        $uptime = null;

        if ($shouldLog) {
            // Status changed or first check, save to database
            $log = UptimeLog::create([
                'project_name' => $projectName,
                'ip_address' => $ipAddress,
                'status' => $status,
                'previous_status' => $previousStatus,
                'message' => $message,
                'duration' => $duration,
                'is_timeout' => $isTimeout,
                'timeout_duration' => $timeout,
                'error_type' => $errorType,
                'checked_at' => Carbon::now(),
            ]);
            $logId = $log->id;
            $uptime = 0; // Just changed, so uptime in new status is 0
        } else {
            // Calculate uptime/downtime since last status change
            // Find the last log where status was different (the point where it changed to current status)
            if ($lastLog) {
                $uptime = abs(Carbon::now()->diffInSeconds($lastLog->checked_at, false));
            }
        }

        return [
            'project_name' => $projectName,
            'status' => $status,
            'previous_status' => $previousStatus,
            'status_changed' => $statusChanged,
            'message' => $message,
            'duration' => $duration,
            'is_timeout' => $isTimeout,
            'timeout_duration' => $timeout,
            'error_type' => $errorType,
            'uptime_seconds' => $uptime,
            'log_id' => $logId,
            'logged' => $shouldLog,
        ];
    }

    /**
     * Test Modbus TCP connection to HMI/PLC
     * This is more appropriate than HTTP ping for industrial devices
     */
    private function checkModbusConnection(string $ipAddress, int $timeout, array $config): array
    {
        $startTime = microtime(true);
        
        // Default Modbus configuration
        $port = $config['port'] ?? 502;
        $unitId = $config['unit_id'] ?? 1;
        $startAddress = $config['start_address'] ?? 0;
        $quantity = $config['quantity'] ?? 1;
        
        try {
            // Try to read a holding register (Function Code 03)
            $request = ReadRegistersBuilder::newReadHoldingRegisters(
                "tcp://{$ipAddress}:{$port}",
                $unitId
            );
            
            // Read register(s)
            for ($i = 0; $i < $quantity; $i++) {
                $address = $startAddress + $i;
                $request->int16($address, "reg_{$address}");
            }

            // Send request with timeout
            $response = (new NonBlockingClient(['readTimeoutSec' => $timeout]))
                ->sendRequests($request->build());
            
            $duration = round((microtime(true) - $startTime) * 1000);
            
            // If we got a response object, connection is successful
            // Even if data is empty, it means device responded to Modbus request
            if ($response) {
                $data = $response->getData();
                $dataCount = is_array($data) ? count($data) : 0;
                
                return [
                    'status' => 'online',
                    'message' => "Modbus connection successful (device responded, {$dataCount} data points)",
                    'duration' => $duration,
                    'is_timeout' => false,
                    'error_type' => null,
                ];
            } else {
                return [
                    'status' => 'offline',
                    'message' => 'Modbus connection failed: No response from device',
                    'duration' => $duration,
                    'is_timeout' => false,
                    'error_type' => 'modbus_no_response',
                ];
            }
            
        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000);
            $errorMessage = $e->getMessage();
            $errorType = 'modbus_error';
            $isTimeout = false;
            
            // Detect Modbus timeout
            if (str_contains($errorMessage, 'timeout') || 
                str_contains($errorMessage, 'timed out')) {
                $isTimeout = true;
                $errorType = 'modbus_timeout';
                $message = "Modbus timeout after {$timeout} seconds";
            } elseif (str_contains($errorMessage, 'Connection refused')) {
                $errorType = 'modbus_connection_refused';
                $message = 'Modbus connection refused: Device not responding on port ' . $port;
            } else {
                $message = 'Modbus connection failed: ' . $errorMessage;
            }
            
            return [
                'status' => 'offline',
                'message' => $message,
                'duration' => $duration,
                'is_timeout' => $isTimeout,
                'error_type' => $errorType,
            ];
        }
    }

    /**
     * Check DWP status: Connection + Data freshness
     * First checks if IP is connected via Modbus, then checks InsDwpCount for data freshness
     */
    private function checkDwpStatus(string $ipAddress, int $timeout, array $config): array
    {
        // First, check if the IP/device is connected
        $connectionResult = $this->checkModbusConnection($ipAddress, $timeout, $config);
        
        // If device is offline, return immediately
        if ($connectionResult['status'] === 'offline') {
            return $connectionResult;
        }
        
        // Device is connected, now check data freshness from InsDwpCount
        try {
            // Get the latest record from InsDwpCount
            $latestCount = InsDwpCount::orderBy('created_at', 'desc')->first();
            
            if (!$latestCount) {
                // No data in database yet
                return [
                    'status' => 'idle',
                    'message' => 'Device connected but no data in database',
                    'duration' => $connectionResult['duration']
                ];
            }
            
            // Calculate time difference between now and last data
            $now = Carbon::now();
            $lastDataTime = Carbon::parse($latestCount->created_at);
            $minutesGap = abs($now->diffInMinutes($lastDataTime, false));
            
            // If gap is more than 5 minutes, status is idle
            if ($minutesGap > 5) {
                return [
                    'status' => 'idle',
                    'message' => "Device connected but no new data for {$minutesGap} minutes (last: {$lastDataTime->format('H:i:s')})",
                    'duration' => $connectionResult['duration']
                ];
            }
            
            // Data is fresh (within 5 minutes)
            return [
                'status' => 'online',
                'message' => "Device online and receiving data (last: {$minutesGap}m ago)",
                'duration' => $connectionResult['duration']
            ];
            
        } catch (\Exception $e) {
            // If there's an error checking the database, return connection status
            return [
                'status' => 'idle',
                'message' => 'Device connected but error checking data: ' . $e->getMessage(),
                'duration' => $connectionResult['duration']
            ];
        }
    }

    /**
     * Check multiple projects at once
     */
    public function checkMultipleProjects(array $projects): array
    {
        $results = [];

        foreach ($projects as $project) {
            $results[] = $this->checkProject(
                $project['name'],
                $project['ip'],
                $project['timeout'] ?? 10,
                $project['type'] ?? 'http',
                $project['modbus_config'] ?? []
            );
        }

        return $results;
    }

    /**
     * Get uptime statistics for a project
     */
    public function getProjectStats(string $projectName, $days = 7): array
    {
        $startDate = Carbon::now()->subDays($days)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $logs = UptimeLog::where('project_name', $projectName)
            ->whereBetween('checked_at', [$startDate, $endDate])
            ->get();

        $total = $logs->count();
        $online = $logs->where('status', 'online')->count();
        $offline = $logs->where('status', 'offline')->count();
        $idle = $logs->where('status', 'idle')->count();

        $uptime = $total > 0 ? round(($online / $total) * 100, 2) : 0;
        $avgDuration = $logs->where('duration', '>', 0)->avg('duration');

        return [
            'project_name' => $projectName,
            'period_days' => $days,
            'total_checks' => $total,
            'online_count' => $online,
            'offline_count' => $offline,
            'idle_count' => $idle,
            'uptime_percentage' => $uptime,
            'avg_response_time' => $avgDuration ? round($avgDuration) : null,
            'last_check' => $logs->sortByDesc('checked_at')->first(),
        ];
    }

    /**
     * Get all projects with their latest status
     */
    public function getAllProjectsStatus(): array
    {
        $latestLogs = UptimeLog::getLatestStatusByProject();

        return $latestLogs->map(function ($log) {
            return [
                'project_name' => $log->project_name,
                'ip_address' => $log->ip_address,
                'status' => $log->status,
                'message' => $log->message,
                'duration' => $log->duration,
                'checked_at' => $log->checked_at,
                'status_color' => $log->status_color,
            ];
        })->toArray();
    }

    /**
     * Clean old logs (older than specified days)
     */
    public function cleanOldLogs(int $days = 30): int
    {
        $cutoffDate = Carbon::now()->subDays($days);
        
        return UptimeLog::where('checked_at', '<', $cutoffDate)->delete();
    }
}
