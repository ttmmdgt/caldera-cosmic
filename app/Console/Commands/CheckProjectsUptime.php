<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\UptimeMonitorService;

class CheckProjectsUptime extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'uptime:check {--project=* : Specific projects to check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check uptime status for configured projects';

    /**
     * Execute the console command.
     */
    public function handle(UptimeMonitorService $service)
    {
        $this->info('Starting uptime checks...');

        // Define your projects here or load from config
        $projects = $this->getProjects();

        // Filter by specific projects if provided
        if ($projectNames = $this->option('project')) {
            $projects = array_filter($projects, function($project) use ($projectNames) {
                return in_array($project['name'], $projectNames);
            });
        }

        if (empty($projects)) {
            $this->error('No projects configured to check.');
            return Command::FAILURE;
        }

        $results = [];
        
        foreach ($projects as $project) {
            $this->line("Checking {$project['name']}...");
            
            $result = $service->checkProject(
                $project['name'],
                $project['ip'],
                $project['timeout'] ?? 10,
                $project['type'] ?? 'http',
                $project['modbus_config'] ?? []
            );

            $results[] = $result;

            $statusColor = match($result['status']) {
                'online' => 'green',
                'offline' => 'red',
                'idle' => 'yellow',
                default => 'white'
            };

            $this->line("  Type: " . ($project['type'] ?? 'http'));
            $this->line("  Status: <fg={$statusColor}>{$result['status']}</>");
            $this->line("  Duration: {$result['duration']}ms");
            $this->line("  Message: {$result['message']}");
            
            // Show timeout information if applicable
            if ($result['is_timeout'] ?? false) {
                $this->line("  <fg=red>⚠ TIMEOUT</> Request timed out after {$result['timeout_duration']}s");
            }
            
            // Show error type if present
            if (!empty($result['error_type'])) {
                $errorTypeFormatted = str_replace('_', ' ', ucwords($result['error_type'], '_'));
                $this->line("  Error Type: <fg=yellow>{$errorTypeFormatted}</>");
            }
            
            // Show status change information
            if ($result['status_changed']) {
                $changeInfo = $result['previous_status'] 
                    ? "<fg=yellow>Status changed: {$result['previous_status']} → {$result['status']}</>"
                    : "<fg=cyan>First check</>";
                $this->line("  Change: {$changeInfo}");
            } else {
                // Show how long it's been in current status
                if (isset($result['uptime_seconds'])) {
                    $uptimeFormatted = $this->formatUptime($result['uptime_seconds']);
                    $this->line("  Uptime: <fg=gray>{$uptimeFormatted} in '{$result['status']}' status</>");
                }
            }
            
            // Show if data was logged or skipped (smart logging)
            $logStatus = $result['logged'] ?? false ? '<fg=cyan>✓ Logged (status changed)</>' : '<fg=gray>○ Skipped (no change)</>';
            $this->line("  Database: {$logStatus}");
            $this->newLine();
        }

        // Summary
        $online = count(array_filter($results, fn($r) => $r['status'] === 'online'));
        $offline = count(array_filter($results, fn($r) => $r['status'] === 'offline'));
        $idle = count(array_filter($results, fn($r) => $r['status'] === 'idle'));
        $timeouts = count(array_filter($results, fn($r) => $r['is_timeout'] ?? false));

        $this->info("Check completed!");
        $this->table(
            ['Status', 'Count'],
            [
                ['Online', $online],
                ['Offline', $offline],
                ['Idle', $idle],
                ['Timeouts', "<fg=red>{$timeouts}</>"],
                ['Total', count($results)],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * Get list of projects to monitor
     * You can move this to a config file later
     */
    private function getProjects(): array
    {
        // Load projects from config file
        return config('uptime.projects', []);
    }

    /**
     * Format uptime seconds to human readable string
     */
    private function formatUptime(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} seconds";
        }
        
        $minutes = floor($seconds / 60);
        if ($minutes < 60) {
            return "{$minutes} minutes";
        }
        
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        
        if ($hours < 24) {
            return $remainingMinutes > 0 
                ? "{$hours}h {$remainingMinutes}m" 
                : "{$hours} hours";
        }
        
        $days = floor($hours / 24);
        $remainingHours = $hours % 24;
        
        return $remainingHours > 0 
            ? "{$days}d {$remainingHours}h" 
            : "{$days} days";
    }
}
