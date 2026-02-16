<?php

namespace App\Services;

use App\Models\UptimeLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class UptimeCalculatorService
{
    private const TIMEOUT_THRESHOLD_SECONDS = 300; // 5 minutes
    
    public function calculateStats(string $projectName, Carbon $start, Carbon $end): array
    {
        $logs = $this->getLogs($projectName, $start, $end);
        
        if ($logs->isEmpty()) {
            return $this->getEmptyStats();
        }
        
        $onlineDuration  = $this->calculateOnlineDuration($logs, $end);
        $offlineDuration = $this->calculateOfflineDuration($logs, $end);
        $timeoutDuration = $this->calculateTimeoutDuration($logs, $end);
        
        $totalDuration = $onlineDuration + $offlineDuration + $timeoutDuration;
        
        return [
            'online_duration' => $onlineDuration,
            'offline_duration' => $offlineDuration,
            'timeout_duration' => $timeoutDuration,
            'online_percentage' => $this->calculatePercentage($onlineDuration, $totalDuration),
            'offline_percentage' => $this->calculatePercentage($offlineDuration, $totalDuration),
            'timeout_percentage' => $this->calculatePercentage($timeoutDuration, $totalDuration),
        ];
    }
    
    private function getLogs(string $projectName, Carbon $start, Carbon $end): Collection
    {
        return UptimeLog::where('project_name', $projectName)
            ->whereBetween('checked_at', [$start, $end])
            ->orderBy('checked_at', 'asc')
            ->get();
    }
    
    private function calculateOnlineDuration(Collection $logs, Carbon $end): int
    {
        $totalSeconds = 0;
        $onlineStartTime = null;
        
        foreach ($logs as $log) {
            if ($log->status === 'online') {
                $onlineStartTime ??= $log->checked_at;
            } elseif ($onlineStartTime !== null) {
                $totalSeconds += $onlineStartTime->diffInSeconds($log->checked_at);
                $onlineStartTime = null;
            }
        }
        
        if ($onlineStartTime !== null) {
            $totalSeconds += $onlineStartTime->diffInSeconds(Carbon::now()->min($end));
        }
        
        return $totalSeconds;
    }
    
    private function calculateOfflineDuration(Collection $logs, Carbon $end): int
    {
        // Only count offline periods >= 5 minutes (true offline)
        return $this->calculateStatusDuration($logs, 'offline', $end, self::TIMEOUT_THRESHOLD_SECONDS, true);
    }
    
    private function calculateTimeoutDuration(Collection $logs, Carbon $end): int
    {
        // Only count offline periods < 5 minutes (RTO)
        return $this->calculateStatusDuration($logs, 'offline', $end, self::TIMEOUT_THRESHOLD_SECONDS, false);
    }
    
    private function calculateStatusDuration(
        Collection $logs, 
        string $status, 
        Carbon $end, 
        ?int $threshold = null,
        ?bool $countAboveThreshold = null
    ): int {
        $totalSeconds = 0;
        $count = $logs->count();
        
        for ($i = 0; $i < $count; $i++) {
            if ($logs[$i]->status !== $status) {
                continue;
            }
            
            $startTime = $logs[$i]->checked_at;
            $endTime = $this->findNextDifferentStatus($logs, $i, $status) 
                ?? Carbon::now()->min($end);
            
            $duration = $startTime->diffInSeconds($endTime);
            
            // Determine if we should count this duration based on threshold
            if ($threshold === null) {
                $totalSeconds += $duration;
            } elseif ($countAboveThreshold === true && $duration >= $threshold) {
                // Count only durations >= threshold (true offline)
                $totalSeconds += $duration;
            } elseif ($countAboveThreshold === false && $duration < $threshold) {
                // Count only durations < threshold (RTO)
                $totalSeconds += $duration;
            }
            
            // Skip consecutive logs with same status
            while ($i + 1 < $count && $logs[$i + 1]->status === $status) {
                $i++;
            }
        }
        
        return $totalSeconds;
    }
    
    private function findNextDifferentStatus(Collection $logs, int $currentIndex, string $status): ?Carbon
    {
        $count = $logs->count();
        
        for ($j = $currentIndex + 1; $j < $count; $j++) {
            if ($logs[$j]->status !== $status) {
                return $logs[$j]->checked_at;
            }
        }
        
        return null;
    }
    
    private function calculatePercentage(int $part, int $total): float
    {
        return $total > 0 ? round(($part / $total) * 100, 2) : 0;
    }
    
    private function getEmptyStats(): array
    {
        return [
            'online_duration' => 0,
            'offline_duration' => 0,
            'timeout_duration' => 0,
            'online_percentage' => 0,
            'offline_percentage' => 0,
            'timeout_percentage' => 0,
        ];
    }
}