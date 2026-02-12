<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Attributes\Url;
use App\Models\UptimeLog;
use App\Models\Project;
use App\Services\UptimeMonitorService;
use Carbon\Carbon;

new #[Layout("layouts.app")] class extends Component {
    use WithPagination;

    #[Url]
    public string $start_at = "";

    #[Url]
    public string $end_at = "";

    #[Url]
    public string $project = "";

    #[Url]
    public string $status = "";

    public int $perPage = 20;
    public array $projects = [];
    public array $statistics = [];
    public array $liveStatus = [];

    public function mount()
    {
        if (!$this->start_at || !$this->end_at) {
            $this->setToday();
        }

        $this->loadProjects();
        $this->loadStatistics();
        $this->loadLiveStatus();
    }

    private function loadProjects()
    {
        // Get distinct project groups from active projects
        $this->projects = Project::active()
            ->whereNotNull('project_group')
            ->distinct()
            ->pluck('project_group')
            ->toArray();
    }

    private function loadStatistics()
    {
        $start = Carbon::parse($this->start_at);
        $end = Carbon::parse($this->end_at);

        $query = UptimeLog::whereBetween('checked_at', [$start, $end]);

        if ($this->project) {
            // Get all project names in the selected group
            $allProjects = Project::active()->get();
            $allProjects = $allProjects->where('project_group', $this->project);
            $query->whereIn('ip_address', $allProjects->pluck('ip')->toArray());
        }

        $logs = $query->get();

        $this->statistics = [
            'total' => $logs->count(),
            'online' => $logs->where('status', 'online')->count(),
            'offline' => $logs->where('status', 'offline')->count(),
            'idle' => $logs->where('status', 'idle')->count(),
            'timeouts' => $logs->where('is_timeout', true)->count(),
            'uptime_percentage' => $logs->count() > 0 
                ? round(($logs->where('status', 'online')->count() / $logs->count()) * 100, 2) 
                : 0,
            'avg_duration' => $logs->where('duration', '>', 0)->avg('duration') 
                ? round($logs->where('duration', '>', 0)->avg('duration')) 
                : 0,
        ];
    }

    public function loadLiveStatus()
    {
        // Get latest status for each project
        $allProjects = Project::active()->get();
        $this->liveStatus = [];

        // Filter projects if a specific project group is selected
        if ($this->project) {
            $allProjects = $allProjects->where('project_group', $this->project);
        }

        foreach ($allProjects as $project) {
            $latestLog = UptimeLog::where('ip_address', $project->ip)
                ->orderBy('checked_at', 'desc')
                ->first();

            if ($latestLog) {
                // Calculate total online duration for today (or current filter period)
                $start = Carbon::parse($this->start_at);
                $end = Carbon::parse($this->end_at);
                $uptimeSeconds = $this->calculateTotalOnlineDuration($project->ip, $start, $end);
                $downtimeSeconds = $this->calculateTotalOfflineDuration($project->ip, $start, $end);
                
                $this->liveStatus[] = [
                    'name' => $project->name,
                    'status' => $latestLog->status,
                    'ip' => $latestLog->ip_address,
                    'message' => $latestLog->message,
                    'checked_at' => $latestLog->checked_at,
                    'uptime_seconds' => $uptimeSeconds,
                    'uptime_formatted' => $this->formatUptime($uptimeSeconds),
                    'downtime_seconds' => $downtimeSeconds,
                    'downtime_formatted' => $this->formatUptime($downtimeSeconds),
                    'previous_status' => $latestLog->previous_status,
                ];
            } else {
                $this->liveStatus[] = [
                    'name' => $project->name,
                    'status' => 'unknown',
                    'ip' => $project->ip,
                    'message' => 'No data yet',
                    'checked_at' => null,
                    'uptime_seconds' => 0,
                    'uptime_formatted' => '-',
                    'downtime_seconds' => 0,
                    'downtime_formatted' => '-',
                    'previous_status' => null,
                ];
            }
        }
    }

    private function calculateTotalOnlineDuration(string $ip, Carbon $start, Carbon $end): int
    {
        // Get all status change logs within the date range, ordered by time
        $logs = UptimeLog::where('ip_address', $ip)
            ->whereBetween('checked_at', [$start, $end])
            ->orderBy('checked_at', 'asc')
            ->get();

        if ($logs->isEmpty()) {
            return 0;
        }

        $totalOnlineSeconds = 0;
        $onlineStartTime = null;

        foreach ($logs as $log) {
            if ($log->status === 'online') {
                if ($onlineStartTime === null) {
                    // Start of an online period
                    $onlineStartTime = $log->checked_at;
                }
            } else {
                // Status changed to offline or idle
                if ($onlineStartTime !== null) {
                    // End of an online period, calculate duration
                    $totalOnlineSeconds += $onlineStartTime->diffInSeconds($log->checked_at);
                    $onlineStartTime = null;
                }
            }
        }

        // If still online at the end of the period, add the remaining duration
        if ($onlineStartTime !== null) {
            $endTime = Carbon::now()->min($end);
            $totalOnlineSeconds += $onlineStartTime->diffInSeconds($endTime);
        }

        return $totalOnlineSeconds;
    }

    private function calculateTotalOfflineDuration(string $ip, Carbon $start, Carbon $end): int
    {
        // Get all status change logs within the date range, ordered by time
        $logs = UptimeLog::where('ip_address', $ip)
            ->whereBetween('checked_at', [$start, $end])
            ->orderBy('checked_at', 'asc')
            ->get();

        if ($logs->isEmpty()) {
            return 0;
        }

        $totalOfflineSeconds = 0;
        $offlineStartTime = null;

        foreach ($logs as $log) {
            if ($log->status === 'offline') {
                if ($offlineStartTime === null) {
                    // Start of an offline period
                    $offlineStartTime = $log->checked_at;
                }
            } else {
                // Status changed to online or idle
                if ($offlineStartTime !== null) {
                    // End of an offline period, calculate duration
                    $totalOfflineSeconds += $offlineStartTime->diffInSeconds($log->checked_at);
                    $offlineStartTime = null;
                }
            }
        }

        // If still offline at the end of the period, add the remaining duration
        if ($offlineStartTime !== null) {
            $endTime = Carbon::now()->min($end);
            $totalOfflineSeconds += $offlineStartTime->diffInSeconds($endTime);
        }

        return $totalOfflineSeconds;
    }

    private function formatUptime(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        
        $minutes = floor($seconds / 60);
        if ($minutes < 60) {
            return "{$minutes}m";
        }
        
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        
        if ($hours < 24) {
            return $remainingMinutes > 0 
                ? "{$hours}h {$remainingMinutes}m" 
                : "{$hours}h";
        }
        
        $days = floor($hours / 24);
        $remainingHours = $hours % 24;
        
        return $remainingHours > 0 
            ? "{$days}d {$remainingHours}h" 
            : "{$days}d";
    }

    private function getLogsQuery()
    {
        $start = Carbon::parse($this->start_at);
        $end = Carbon::parse($this->end_at);

        $query = UptimeLog::whereBetween('checked_at', [$start, $end]);

        if ($this->project) {
            // Get all project names in the selected group
            $allProjects = Project::active()->get();
            $allProjects = $allProjects->where('project_group', $this->project);
            $query->whereIn('ip_address', $allProjects->pluck('ip')->toArray());
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        return $query->orderBy('checked_at', 'DESC');
    }

    public function with(): array
    {
        $logs = $this->getLogsQuery()->paginate($this->perPage);
        return [
            'logs' => $logs,
        ];
    }

    public function loadMore()
    {
        $this->perPage += 20;
    }

    public function refreshStats()
    {
        $this->loadStatistics();
        $this->loadLiveStatus();
        $this->js('toast("' . __("Statistik diperbarui") . '", { type: "success" })');
    }

    // Date range filters
    public function setToday()
    {
        $this->start_at = Carbon::now()->startOfDay()->format('Y-m-d\TH:i');
        $this->end_at = Carbon::now()->endOfDay()->format('Y-m-d\TH:i');
        $this->loadStatistics();
    }

    public function setYesterday()
    {
        $this->start_at = Carbon::yesterday()->startOfDay()->format('Y-m-d\TH:i');
        $this->end_at = Carbon::yesterday()->endOfDay()->format('Y-m-d\TH:i');
        $this->loadStatistics();
    }

    public function setThisWeek()
    {
        $this->start_at = Carbon::now()->startOfWeek()->format('Y-m-d\TH:i');
        $this->end_at = Carbon::now()->endOfWeek()->format('Y-m-d\TH:i');
        $this->loadStatistics();
    }

    public function setLastWeek()
    {
        $this->start_at = Carbon::now()->subWeek()->startOfWeek()->format('Y-m-d\TH:i');
        $this->end_at = Carbon::now()->subWeek()->endOfWeek()->format('Y-m-d\TH:i');
        $this->loadStatistics();
    }

    public function setThisMonth()
    {
        $this->start_at = Carbon::now()->startOfMonth()->format('Y-m-d\TH:i');
        $this->end_at = Carbon::now()->endOfMonth()->format('Y-m-d\TH:i');
        $this->loadStatistics();
    }

    public function updated($property)
    {
        if (in_array($property, ['start_at', 'end_at', 'project', 'status'])) {
            $this->loadStatistics();
            $this->loadLiveStatus();
            $this->resetPage();
        }
    }
};

?>

<div class="p-6 max-w-7xl mx-auto text-neutral-800 dark:text-neutral-200" wire:poll.30s="loadLiveStatus">
    <!-- Header Section -->
    <div class="mb-8">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div>
                    <h1 class="text-2xl font-bold text-neutral-800 dark:text-white mb-1">Caldera Uptime Monitoring</h1>
                    <p class="text-sm text-neutral-500">Real-time system uptime for tracking All Services</p>
                </div>
                @auth
                <div>
                    <a wire:navigate href="{{ route('uptime.projects.index') }}">
                        <x-secondary-button type="button" class="text-xs">
                            <i class="icon-settings"></i>
                            {{ __('Settings') }}
                        </x-secondary-button>
                    </a>
                </div>
                @endauth
            </div>
            <div class="flex items-center gap-2 text-xs text-neutral-400">
                <div class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
                <span>Updates every 30s</span>
            </div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="sticky top-0 z-20 dark:bg-neutral-950 py-4 -mx-4 px-4 mb-6">
        <div class="bg-white dark:bg-neutral-800 rounded-xl border border-neutral-200 dark:border-neutral-700 p-5 shadow-lg">
            <div class="flex flex-wrap items-end gap-4">
                <div>
                <div class="flex mb-2 text-xs text-neutral-500">
                    <div class="flex">
                        <x-dropdown align="left" width="48">
                            <x-slot name="trigger">
                                <x-text-button class="uppercase ml-3">
                                    {{ __("Rentang") }}
                                    <i class="icon-chevron-down ms-1"></i>
                                </x-text-button>
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link href="#" wire:click.prevent="setToday">
                                    {{ __("Hari ini") }}
                                </x-dropdown-link>
                                <x-dropdown-link href="#" wire:click.prevent="setYesterday">
                                    {{ __("Kemarin") }}
                                </x-dropdown-link>
                                <hr class="border-neutral-300 dark:border-neutral-600" />
                                <x-dropdown-link href="#" wire:click.prevent="setThisWeek">
                                    {{ __("Minggu ini") }}
                                </x-dropdown-link>
                                <x-dropdown-link href="#" wire:click.prevent="setLastWeek">
                                    {{ __("Minggu lalu") }}
                                </x-dropdown-link>
                                <hr class="border-neutral-300 dark:border-neutral-600" />
                                <x-dropdown-link href="#" wire:click.prevent="setThisMonth">
                                    {{ __("Bulan ini") }}
                                </x-dropdown-link>
                                <x-dropdown-link href="#" wire:click.prevent="setLastMonth">
                                    {{ __("Bulan lalu") }}
                                </x-dropdown-link>
                            </x-slot>
                        </x-dropdown>
                    </div>
                </div>
                <div class="flex gap-3">
                    <x-text-input wire:model.live="start_at" type="datetime-local" class="w-40" />
                    <x-text-input wire:model.live="end_at" type="datetime-local" class="w-40" />
                </div>
            </div>

            <div class="h-10 w-px bg-neutral-200 dark:bg-neutral-700"></div>

            <!-- Project & Status -->
            <div class="flex gap-3 flex-1 min-w-0">
                <div class="flex-1 min-w-[140px]">
                    <label class="block uppercase text-xs font-small text-neutral-700 dark:text-neutral-300 mb-2">Project Group</label>
                    <select wire:model.live="project" 
                        class="w-full px-3 py-2 text-sm border border-neutral-300 dark:border-neutral-600 rounded-lg bg-white dark:bg-neutral-900 text-neutral-800 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow">
                        <option value="">Select Group</option>
                        @foreach($projects as $proj)
                            <option value="{{ $proj }}">{{ $proj }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex-1 min-w-[120px]">
                    <label class="block uppercase text-xs font-small text-neutral-700 dark:text-neutral-300 mb-2">Status</label>
                    <select wire:model.live="status" 
                        class="w-full px-3 py-2 text-sm border border-neutral-300 dark:border-neutral-600 rounded-lg bg-white dark:bg-neutral-900 text-neutral-800 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow">
                        <option value="">All Status</option>
                        <option value="online">Online</option>
                        <option value="offline">Offline</option>
                        <option value="idle">Idle</option>
                        <option value="timeout">Timeout</option>
                    </select>
                </div>
            </div>

            <!-- Refresh Button -->
            <div>
                <label class="block text-xs font-medium text-transparent mb-2">Action</label>
                <x-primary-button type="button" wire:click="refreshStats" class="h-9">
                    <i class="icon-rotate-cw"></i>
                    {{ __('Refresh') }}
                </x-primary-button>
            </div>
        </div>
    </div>

     <!-- Statistics Overview -->
    @if($project)
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3 mb-8 mt-4">
        <div class="bg-white dark:bg-neutral-800 rounded-xl p-4 border border-neutral-400 dark:border-neutral-700 shadow">
            <div class="flex items-center justify-between mb-2">
                <div class="w-8 h-8 bg-green-500/10 dark:bg-green-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-4 h-4 text-green-600 dark:text-green-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                </div>
            </div>
            <div class="text-2xl font-bold text-neutral-800 dark:text-neutral-100 mb-0.5">{{ number_format($statistics['online']) }}</div>
            <div class="text-xs font-medium text-neutral-600 dark:text-neutral-300">Online</div>
        </div>

        <div class="bg-white dark:bg-neutral-800 rounded-xl p-4 border border-neutral-400 dark:border-neutral-700 shadow">
            <div class="flex items-center justify-between mb-2">
                <div class="w-8 h-8 bg-red-500/10 dark:bg-red-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-4 h-4 text-red-600 dark:text-red-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                </div>
            </div>
            <div class="text-2xl font-bold text-neutral-800 dark:text-neutral-100 mb-0.5">{{ number_format($statistics['offline']) }}</div>
            <div class="text-xs font-medium text-neutral-600 dark:text-neutral-300">Offline</div>
        </div>

        <div class="bg-white dark:bg-neutral-800 rounded-xl p-4 border border-neutral-400 dark:border-neutral-700 shadow">
            <div class="flex items-center justify-between mb-2">
                <div class="w-8 h-8 bg-amber-500/10 dark:bg-amber-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-4 h-4 text-amber-600 dark:text-amber-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM7 9a1 1 0 100-2 1 1 0 000 2zm7-1a1 1 0 11-2 0 1 1 0 012 0zm-.464 5.535a1 1 0 10-1.415-1.414 3 3 0 01-4.242 0 1 1 0 00-1.415 1.414 5 5 0 007.072 0z" clip-rule="evenodd"></path>
                    </svg>
                </div>
            </div>
            <div class="text-2xl font-bold text-neutral-800 dark:text-neutral-100 mb-0.5">{{ number_format($statistics['idle']) }}</div>
            <div class="text-xs font-medium text-neutral-600 dark:text-neutral-300">Idle</div>
        </div>

        <div class="bg-white dark:bg-neutral-800 rounded-xl p-4 border border-neutral-400 dark:border-neutral-700 shadow">
            <div class="flex items-center justify-between mb-2">
                <div class="w-8 h-8 bg-orange-500/10 dark:bg-orange-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-4 h-4 text-orange-600 dark:text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
            </div>
            <div class="text-2xl font-bold text-neutral-800 dark:text-neutral-100 mb-0.5">{{ number_format($statistics['timeouts']) }}</div>
            <div class="text-xs font-medium text-neutral-600 dark:text-neutral-300">Timeouts</div>
        </div>

        <div class="bg-white dark:bg-neutral-800 rounded-xl p-4 border border-neutral-400 dark:border-neutral-700 shadow">
            <div class="flex items-center justify-between mb-2">
                <div class="w-8 h-8 bg-violet-500/10 dark:bg-violet-500/20 rounded-lg flex items-center justify-center">
                    <svg class="w-4 h-4 text-violet-600 dark:text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                    </svg>
                </div>
            </div>
            <div class="text-2xl font-bold text-neutral-800 dark:text-neutral-100 mb-0.5">{{ $statistics['uptime_percentage'] }}%</div>
            <div class="text-xs font-medium text-neutral-600 dark:text-neutral-300">Uptime Rate</div>
        </div>
    </div>
    @endif

    <!-- Live Status Grid -->
    @if($project && !empty($liveStatus))
    <div class="mb-8">
        <div class="grid grid-cols-1 md:grid-cols-4 xl:grid-cols-4 gap-4">
            @foreach($liveStatus as $item)
                <div class="group relative bg-white dark:bg-neutral-800 rounded-xl border border-neutral-200 dark:border-neutral-700 hover:border-neutral-300 dark:hover:border-neutral-600 transition-all duration-200 overflow-hidden shadow">
                    <div class="p-5">
                        <!-- Header -->
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex-1 min-w-0">
                                <h3 class="text-base font-semibold text-neutral-800 dark:text-white truncate mb-1">
                                    {{ $item['name'] }}
                                </h3>
                                <div class="flex items-center gap-2 text-xs text-neutral-500">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path>
                                    </svg>
                                    <span class="font-mono">{{ $item['ip'] }}</span>
                                </div>
                            </div>
                            
                            @if($item['status'] === 'online')
                                <div class="relative flex items-center justify-center w-9 h-9 bg-green-50 dark:bg-green-900/20 rounded-lg">
                                    <div class="absolute w-9 h-9 bg-green-400 rounded-lg opacity-20 animate-ping"></div>
                                    <svg class="w-4 h-4 text-green-500 relative z-10" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                            @elseif($item['status'] === 'offline')
                                <div class="flex items-center justify-center w-9 h-9 bg-red-50 dark:bg-red-900/20 rounded-lg">
                                    <svg class="w-4 h-4 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                            @else
                                <div class="flex items-center justify-center w-9 h-9 bg-amber-50 dark:bg-amber-900/20 rounded-lg">
                                    <svg class="w-4 h-4 text-amber-500" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                            @endif
                        </div>

                        <!-- Status Info -->
                        <div class="space-y-1">
                            <div class="flex items-center justify-between py-2 px-3 bg-neutral-100 dark:bg-neutral-900/50 rounded-lg">
                                <span class="text-xs font-medium text-neutral-500 dark:text-neutral-400">Current Status</span>
                                <span class="text-sm font-semibold 
                                    {{ $item['status'] === 'online' ? 'text-green-600 dark:text-green-400' : ($item['status'] === 'offline' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400') }}">
                                    {{ ucfirst($item['status']) }}
                                </span>
                            </div>

                            <div class="flex items-center justify-between py-2 px-3 bg-neutral-100 dark:bg-neutral-900/50 rounded-lg">
                                <span class="text-xs font-medium text-neutral-500 dark:text-neutral-400">Uptime Duration</span>
                                <span class="text-sm font-bold text-neutral-800 dark:text-white font-mono">
                                    {{ $item['uptime_formatted'] }}
                                </span>
                            </div>

                            <div class="flex items-center justify-between py-2 px-3 bg-neutral-100 dark:bg-neutral-900/50 rounded-lg">
                                <span class="text-xs font-medium text-neutral-500 dark:text-neutral-400">Downtime Duration</span>
                                <span class="text-sm font-bold text-red-600 dark:text-red-400 font-mono">
                                    {{ $item['downtime_formatted'] }}
                                </span>
                            </div>
                        </div>

                        <!-- Footer Info -->
                        <div class="mt-4 pt-3 border-t border-neutral-100 dark:border-neutral-700 space-y-2">
                            @if($item['previous_status'])
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="px-2 py-0.5 rounded-md font-medium
                                        {{ $item['previous_status'] === 'online' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' : ($item['previous_status'] === 'offline' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300') }}">
                                        {{ ucfirst($item['previous_status']) }}
                                    </span>
                                    <svg class="w-3 h-3 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                                    </svg>
                                    <span class="px-2 py-0.5 rounded-md font-medium
                                        {{ $item['status'] === 'online' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' : ($item['status'] === 'offline' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300') }}">
                                        {{ ucfirst($item['status']) }}
                                    </span>
                                </div>
                            @endif

                            @if($item['checked_at'])
                                <div class="flex items-center gap-1.5 text-xs text-neutral-500">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <span>{{ $item['checked_at']->diffForHumans() }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @elseif(!$project)
        <div class="mb-8">
            <div class="bg-white dark:bg-neutral-800 rounded-xl border border-neutral-200 dark:border-neutral-700 py-16">
                <div class="text-center">
                    <svg class="mx-auto h-12 w-12 text-neutral-300 dark:text-neutral-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                    </svg>
                    <h3 class="mt-3 text-sm font-medium text-neutral-700 dark:text-neutral-300">Please select a project group</h3>
                    <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Choose a project group from the filter above to view live status</p>
                </div>
                <div class="flex items-center justify-center mt-4 text-xs text-neutral-400 gap-2">
                    <div class="p-3 border border-neutral-400 rounded hover:bg-neutral-300 dark:hover:bg-neutral-700 cursor-pointer" wire:click="$set('project', 'DWP')">
                        <img src="/ink-dwp.svg" alt="DWP Logo" class="inline-block h-6 mr-2" />
                        <span class="text-neutral-700 font-bold">DWP Monitoring</span>
                    </div>
                    <div class="p-3 border border-neutral-400 rounded hover:bg-neutral-300 dark:hover:bg-neutral-700 cursor-pointer" wire:click="$set('project', 'IP_STC')">
                        <img src="/ink-stc.svg" alt="IP STC Logo" class="inline-block h-6 mr-2" />
                        <span class="text-neutral-700 font-bold">IP STC</span>
                    </div>
                    <div class="p-3 border border-neutral-400 rounded hover:bg-neutral-300 dark:hover:bg-neutral-700 cursor-pointer" wire:click="$set('project', 'RTC')">
                        <img src="/ink-rtc.svg" alt="RTC Logo" class="inline-block h-6 mr-2" />
                        <span class="text-neutral-700 font-bold">RTC</span>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="mb-8">
            <div class="text-center text-neutral-500 dark:text-neutral-400">
                No live status data available for the selected filters.
            </div>
        </div>
    @endif

    <!-- Logs Table -->
    @if(!$project)
    @elseif (!$logs->count())
        <div class="bg-white dark:bg-neutral-800 rounded-xl border border-neutral-200 dark:border-neutral-700 py-16">
            <div class="text-center">
                <svg class="mx-auto h-16 w-16 text-neutral-300 dark:text-neutral-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                </svg>
                <h3 class="mt-3 text-sm font-medium text-neutral-700 dark:text-neutral-300">No logs found</h3>
                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Try adjusting your filters to see more results</p>
            </div>
        </div>
    @else
        <div class="bg-white dark:bg-neutral-800 rounded-xl border border-neutral-200 dark:border-neutral-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                    <thead>
                        <tr class="bg-neutral-50 dark:bg-neutral-900/50">
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-neutral-600 dark:text-neutral-300 uppercase tracking-wider">Timestamp</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-neutral-600 dark:text-neutral-300 uppercase tracking-wider">Project</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-neutral-600 dark:text-neutral-300 uppercase tracking-wider">IP Address</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-neutral-600 dark:text-neutral-300 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-neutral-600 dark:text-neutral-300 uppercase tracking-wider">Timeout</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-neutral-600 dark:text-neutral-300 uppercase tracking-wider">Error Type</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-neutral-600 dark:text-neutral-300 uppercase tracking-wider">Status Change</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-neutral-600 dark:text-neutral-300 uppercase tracking-wider">Response Time</th>
                            <th class="px-4 py-3.5 text-left text-xs font-semibold text-neutral-600 dark:text-neutral-300 uppercase tracking-wider">Message</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        @foreach($logs as $log)
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-900/30 transition-colors">
                                <td class="px-4 py-3.5 text-sm text-neutral-700 dark:text-neutral-300 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        <span class="font-mono text-xs">{{ $log->checked_at->format('Y-m-d H:i:s') }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3.5 text-sm">
                                    <span class="font-medium text-neutral-800 dark:text-white">{{ $log->project_name }}</span>
                                </td>
                                <td class="px-4 py-3.5 text-sm">
                                    <span class="font-mono text-xs text-neutral-600 dark:text-neutral-400">{{ $log->ip_address }}</span>
                                </td>
                                <td class="px-4 py-3.5 text-sm">
                                    @if($log->status === 'online')
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300 border border-green-200 dark:border-green-800">
                                            <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span>
                                            Online
                                        </span>
                                    @elseif($log->status === 'offline')
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300 border border-red-200 dark:border-red-800">
                                            <span class="w-1.5 h-1.5 bg-red-500 rounded-full"></span>
                                            Offline
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                                            <span class="w-1.5 h-1.5 bg-amber-500 rounded-full"></span>
                                            Idle
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 text-sm text-center">
                                    @if($log->is_timeout)
                                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs font-medium bg-orange-50 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300 border border-orange-200 dark:border-orange-800">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            Timeout
                                        </span>
                                    @else
                                        <span class="text-xs text-neutral-400">-</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 text-sm">
                                    @if($log->error_type)
                                        <span class="px-2 py-1 rounded-md text-xs font-medium bg-neutral-100 text-neutral-700 dark:bg-neutral-700 dark:text-neutral-300">
                                            {{ str_replace('_', ' ', ucwords($log->error_type, '_')) }}
                                        </span>
                                    @else
                                        <span class="text-xs text-neutral-400">-</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 text-sm">
                                    @if($log->previous_status)
                                        <div class="flex items-center gap-2">
                                            <span class="px-2 py-0.5 rounded-md text-xs font-medium
                                                {{ $log->previous_status === 'online' ? 'bg-green-50 text-green-600 dark:bg-green-900/20 dark:text-green-300' : ($log->previous_status === 'offline' ? 'bg-red-50 text-red-600 dark:bg-red-900/20 dark:text-red-300' : 'bg-amber-50 text-amber-600 dark:bg-amber-900/20 dark:text-amber-300') }}">
                                                {{ ucfirst($log->previous_status) }}
                                            </span>
                                            <svg class="w-3 h-3 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                                            </svg>
                                            <span class="px-2 py-0.5 rounded-md text-xs font-medium
                                                {{ $log->status === 'online' ? 'bg-green-50 text-green-600 dark:bg-green-900/20 dark:text-green-300' : ($log->status === 'offline' ? 'bg-red-50 text-red-600 dark:bg-red-900/20 dark:text-red-300' : 'bg-amber-50 text-amber-600 dark:bg-amber-900/20 dark:text-amber-300') }}">
                                                {{ ucfirst($log->status) }}
                                            </span>
                                        </div>
                                    @else
                                        <span class="text-xs text-neutral-400 italic">First check</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 text-sm">
                                    <span class="font-mono text-xs text-neutral-600 dark:text-neutral-400">{{ $log->formatted_duration }}</span>
                                </td>
                                <td class="px-4 py-3.5 text-sm text-neutral-600 dark:text-neutral-400">
                                    <span class="line-clamp-2">{{ $log->message }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <div class="flex items-center justify-center mt-6 pb-4">
            @if ($logs->hasMorePages())
                <button wire:click="loadMore" 
                    class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-medium text-neutral-700 dark:text-neutral-300 bg-white dark:bg-neutral-800 border border-neutral-300 dark:border-neutral-600 rounded-lg hover:bg-neutral-50 dark:hover:bg-neutral-700 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                    Load More Logs
                </button>
            @else
                <div class="text-sm text-neutral-500 dark:text-neutral-400">
                    <svg class="inline w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    All logs loaded
                </div>
            @endif
        </div>
    @endif
</div>
