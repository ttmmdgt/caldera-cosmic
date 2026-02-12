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

    #[Url(as: 'projects_page')]
    public int $projectsPage = 1;

    public int $perPage = 20;
    public int $projectsPerPage = 4;
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

        $totalUptimeHours = $this->getTotalUptimeHours();
        $totalDowntimeHours = $this->getTotalDowntimeHours();

        $this->statistics = [
            'total'   => $logs->count(),
            'online'  => $logs->where('status', 'online')->count(),
            'offline' => $logs->where('status', 'offline')->count(),
            'idle'    => $logs->where('status', 'idle')->count(),
            'timeouts' => $logs->where('is_timeout', true)->count(),
            'uptime_percentage' => $logs->count() > 0 
                ? round(($logs->where('status', 'online')->count() / $logs->count()) * 100, 2) 
                : 0,
            'avg_duration' => $logs->where('duration', '>', 0)->avg('duration') 
                ? round($logs->where('duration', '>', 0)->avg('duration')) 
                : 0,
            'uptime_hours' => round($totalUptimeHours / 3600, 2),
            'downtime_hours' => round($totalDowntimeHours / 3600, 2),
        ];
    }

    // get total uptime hours all projects(per group)
    private function getTotalUptimeHours()
    {
        $allProjects = Project::active()->get();
        $totalUptimeHours = 0;
        foreach ($allProjects as $project) {
            $totalUptimeHours += $this->calculateTotalOnlineDuration($project->ip, Carbon::parse($this->start_at), Carbon::parse($this->end_at));
        }
        return $totalUptimeHours;
    }

    // get total downtime hours all projects(per group)
    private function getTotalDowntimeHours()
    {
        $allProjects = Project::active()->where('project_group', $this->project)->get();
        $totalDowntimeHours = 0;
        foreach ($allProjects as $project) {
            $totalDowntimeHours += $this->calculateTotalOfflineDuration($project->ip, Carbon::parse($this->start_at), Carbon::parse($this->end_at));
        }
        return $totalDowntimeHours;
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
        
        // Paginate liveStatus manually
        $liveStatusCollection = collect($this->liveStatus);
        $paginatedStatus = new \Illuminate\Pagination\LengthAwarePaginator(
            $liveStatusCollection->forPage($this->projectsPage, $this->projectsPerPage),
            $liveStatusCollection->count(),
            $this->projectsPerPage,
            $this->projectsPage,
            [
                'path' => \Illuminate\Pagination\Paginator::resolveCurrentPath(),
                'pageName' => 'projects_page'
            ]
        );
        
        return [
            'logs' => $logs,
            'paginatedLiveStatus' => $paginatedStatus,
        ];
    }
    
    public function updatedProjectsPage()
    {
        // This will automatically handle the page change
    }

    public function loadMore()
    {
        $this->perPage += 20;
    }

    public function refreshStats()
    {
        $this->loadStatistics();
        $this->loadLiveStatus();
        $this->dispatch('chart-update');
        $this->js('toast("' . __("Statistik diperbarui") . '", { type: "success" })');
    }

    // Date range filters
    public function setToday()
    {
        $this->start_at = Carbon::now()->startOfDay()->format('Y-m-d\TH:i');
        $this->end_at = Carbon::now()->endOfDay()->format('Y-m-d\TH:i');
        $this->loadStatistics();
        $this->loadLiveStatus();
    }

    public function setYesterday()
    {
        $this->start_at = Carbon::yesterday()->startOfDay()->format('Y-m-d\TH:i');
        $this->end_at = Carbon::yesterday()->endOfDay()->format('Y-m-d\TH:i');
        $this->loadStatistics();
        $this->loadLiveStatus();
    }

    public function setThisWeek()
    {
        $this->start_at = Carbon::now()->startOfWeek()->format('Y-m-d\TH:i');
        $this->end_at = Carbon::now()->endOfWeek()->format('Y-m-d\TH:i');
        $this->loadStatistics();
        $this->loadLiveStatus();
    }

    public function setLastWeek()
    {
        $this->start_at = Carbon::now()->subWeek()->startOfWeek()->format('Y-m-d\TH:i');
        $this->end_at = Carbon::now()->subWeek()->endOfWeek()->format('Y-m-d\TH:i');
        $this->loadStatistics();
        $this->loadLiveStatus();
    }

    public function setThisMonth()
    {
        $this->start_at = Carbon::now()->startOfMonth()->format('Y-m-d\TH:i');
        $this->end_at = Carbon::now()->endOfMonth()->format('Y-m-d\TH:i');
        $this->loadStatistics();
        $this->loadLiveStatus();
    }

    public function setLastMonth()
    {
        $this->start_at = Carbon::now()->subMonth()->startOfMonth()->format('Y-m-d\TH:i');
        $this->end_at = Carbon::now()->subMonth()->endOfMonth()->format('Y-m-d\TH:i');
        $this->loadStatistics();
        $this->loadLiveStatus();
    }

    public function updated($property)
    {
        if (in_array($property, ['start_at', 'end_at', 'project', 'status'])) {
            $this->loadStatistics();
            $this->loadLiveStatus();
            $this->resetPage();
            $this->projectsPage = 1; // Reset projects pagination
            $this->dispatch('chart-update');
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
                    <h1 class="text-2xl font-bold text-neutral-800 dark:text-white mb-1">Dashboard Uptime Monitoring</h1>
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

    <!--  -->

    <div class="grid grid-cols-2 gap-4 mt-4">
        <div class="bg-white dark:bg-neutral-800 rounded-xl p-4 dark:border-neutral-700 shadow">
            <div class="grid grid-cols-4 gap-2">
                <div class="text-xs text-neutral-500 col-span-1 bg-green-500/10 dark:bg-green-500/20 rounded-lg p-2">
                    <p class="text-xs text-neutral-500">Online</p>
                    {{ $statistics['online'] }}
                </div>
                <div class="text-xs text-neutral-500 col-span-1 bg-red-500/10 dark:bg-red-500/20 rounded-lg p-2">
                    <p class="text-xs text-neutral-500">Offline</p>
                    {{ $statistics['offline'] }}
                </div>
                <div class="text-xs text-neutral-500 col-span-1 bg-yellow-500/10 dark:bg-yellow-500/20 rounded-lg p-2">
                    <p class="text-xs text-neutral-500">Uptime Rate</p>
                    <p>{{ round($statistics['online'] / ($statistics['online'] + $statistics['offline']) * 100, 2) }}%</p>
                </div>
                <div class="text-xs text-neutral-500 col-span-1 bg-purple-500/10 dark:bg-purple-500/20 rounded-lg p-2">
                    <p class="text-xs text-neutral-500">Downtime Rate</p>
                    <p>{{ round($statistics['offline'] / ($statistics['online'] + $statistics['offline']) * 100, 2) }}%</p>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2 mt-2">
                <div class="text-xs text-neutral-500 col-span-1 bg-red-500/10 dark:bg-red-500/20 rounded-lg p-2">
                    <p class="text-xs text-neutral-500">Total Uptime Hours</p>
                    <p>{{ $statistics['uptime_hours'] }}</p>
                </div>
                <div class="text-xs text-neutral-500 col-span-1 bg-green-500/10 dark:bg-green-500/20 rounded-lg p-2">
                    <p class="text-xs text-neutral-500">Total Downtime Hours</p>
                    <p>{{ $statistics['downtime_hours'] }}</p>
                </div>
            </div>
            <div class="w-full rounded-lg mt-2 p-4">
                <h3 class="text-sm font-semibold text-neutral-700 dark:text-neutral-300 mb-3">Uptime & Downtime Comparison</h3>
                <div wire:ignore id="uptimeChart"></div>
            </div>
        </div>

        <div class="bg-white dark:bg-neutral-800 rounded-xl p-4 dark:border-neutral-700 shadow">
            <div class="grid grid-cols-2 gap-2">
                <div class="text-xs text-neutral-500 col-span-1 bg-green-500/10 dark:bg-green-500/20 rounded-lg p-2">
                    <p class="text-xs text-neutral-500">Shift:</p>
                    1
                </div>
                <div class="text-xs text-neutral-500 col-span-1 bg-red-500/10 dark:bg-red-500/20 rounded-lg p-2">
                    <p class="text-xs text-neutral-500">Count of day working</p>
                    10
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2 mt-2">
                @foreach($paginatedLiveStatus as $status)
                    <div class="text-xs bg-green-500/10 dark:bg-green-500/20 rounded-lg p-3">
                        <div class="space-y-2">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="text-xs text-neutral-500">Project Name</p>
                                    <p class="font-medium text-neutral-800 dark:text-neutral-200">{{ $status['name'] }}</p>
                                </div>
                                <span class="w-3 h-3 rounded-full flex-shrink-0
                                    {{ $status['status'] === 'online' ? 'bg-green-500' : ($status['status'] === 'offline' ? 'bg-red-500' : 'bg-yellow-500') }}"
                                    title="{{ ucfirst($status['status']) }}">
                                </span>
                            </div>
                            <div>
                                <p class="text-xs text-neutral-500">IP Address</p>
                                <p class="text-neutral-800 dark:text-neutral-200">{{ $status['ip'] }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-neutral-500">Status</p>
                                <p class="text-neutral-800 dark:text-neutral-200 capitalize">{{ $status['status'] }}</p>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <p class="text-xs text-neutral-500">Online Duration</p>
                                    <p class="text-green-600 dark:text-green-400 font-medium">{{ $status['uptime_formatted'] }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-neutral-500">Offline Duration</p>
                                    <p class="text-red-600 dark:text-red-400 font-medium">{{ $status['downtime_formatted'] }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            
            <!-- Pagination -->
            @if($paginatedLiveStatus->hasPages())
            <div class="mt-4 flex justify-center items-center gap-2">
                @if($projectsPage > 1)
                    <button wire:click="$set('projectsPage', {{ $projectsPage - 1 }})" 
                        class="px-3 py-1 text-sm border border-neutral-300 dark:border-neutral-600 rounded hover:bg-neutral-100 dark:hover:bg-neutral-700">
                        <i class="icon-chevron-left"></i>
                    </button>
                @endif
                
                <span class="text-sm text-neutral-600 dark:text-neutral-400">
                    Page {{ $projectsPage }} of {{ $paginatedLiveStatus->lastPage() }}
                </span>
                
                @if($projectsPage < $paginatedLiveStatus->lastPage())
                    <button wire:click="$set('projectsPage', {{ $projectsPage + 1 }})" 
                        class="px-3 py-1 text-sm border border-neutral-300 dark:border-neutral-600 rounded hover:bg-neutral-100 dark:hover:bg-neutral-700">
                        <i class="icon-chevron-right"></i>
                    </button>
                @endif
            </div>
            @endif
        </div>  
    </div>
</div>

@script
<script>
    let uptimeChartInstance = null;

    function initUptimeChart() {
        const chartElement = document.querySelector('#uptimeChart');
        if (!chartElement) return;

        // Get data from Livewire component
        const liveStatus = $wire.liveStatus;
        
        if (!liveStatus || liveStatus.length === 0) {
            if (uptimeChartInstance) {
                uptimeChartInstance.destroy();
                uptimeChartInstance = null;
            }
            return;
        }
        
        // Prepare chart data
        const categories = liveStatus.map(status => status.name);
        const uptimePercentages = liveStatus.map(status => {
            const total = status.uptime_seconds + status.downtime_seconds;
            return total > 0 ? Math.round((status.uptime_seconds / total) * 100) : 0;
        });
        const downtimePercentages = liveStatus.map(status => {
            const total = status.uptime_seconds + status.downtime_seconds;
            return total > 0 ? Math.round((status.downtime_seconds / total) * 100) : 0;
        });

        // Check if dark mode is enabled
        const isDarkMode = document.documentElement.classList.contains('dark');
        const textColor = isDarkMode ? '#d4d4d8' : '#3f3f46';
        const gridColor = isDarkMode ? '#404040' : '#e5e5e5';

        // If chart exists, update data instead of recreating
        if (uptimeChartInstance) {
            uptimeChartInstance.updateOptions({
                xaxis: {
                    categories: categories
                },
                colors: ['#22c55e', '#ef4444']
            });
            
            uptimeChartInstance.updateSeries([
                {
                    name: 'Uptime',
                    data: uptimePercentages
                },
                {
                    name: 'Downtime',
                    data: downtimePercentages
                }
            ]);
            return;
        }

        // ApexCharts options
        const options = {
            series: [
                {
                    name: 'Uptime',
                    data: uptimePercentages
                },
                {
                    name: 'Downtime',
                    data: downtimePercentages
                }
            ],
            chart: {
                type: 'bar',
                height: 350,
                stacked: true,
                stackType: '100%',
                toolbar: {
                    show: false
                },
                background: 'transparent',
                foreColor: textColor,
                animations: {
                    enabled: true,
                    speed: 800,
                    animateGradually: {
                        enabled: true,
                        delay: 150
                    },
                    dynamicAnimation: {
                        enabled: true,
                        speed: 350
                    }
                }
            },
            plotOptions: {
                bar: {
                    horizontal: true,
                    borderRadius: 4,
                    barHeight: '70%'
                }
            },
            colors: ['#22c55e', '#ef4444'],
            dataLabels: {
                enabled: true,
                formatter: function(val) {
                    return val.toFixed(0) + '%';
                },
                style: {
                    colors: ['#fff'],
                    fontSize: '12px',
                    fontWeight: 'bold'
                }
            },
            stroke: {
                width: 1,
                colors: ['transparent']
            },
            xaxis: {
                categories: categories,
                labels: {
                    style: {
                        colors: textColor
                    },
                    formatter: function(val) {
                        return val + '%';
                    }
                },
                axisBorder: {
                    color: gridColor
                },
                axisTicks: {
                    color: gridColor
                }
            },
            yaxis: {
                labels: {
                    style: {
                        colors: textColor
                    }
                }
            },
            grid: {
                borderColor: gridColor,
                strokeDashArray: 4,
                xaxis: {
                    lines: {
                        show: true
                    }
                },
                yaxis: {
                    lines: {
                        show: false
                    }
                }
            },
            legend: {
                position: 'bottom',
                horizontalAlign: 'center',
                labels: {
                    colors: textColor
                },
                markers: {
                    radius: 2
                }
            },
            tooltip: {
                theme: isDarkMode ? 'dark' : 'light',
                y: {
                    formatter: function(val) {
                        return val + '%';
                    }
                }
            }
        };

        // Create chart
        uptimeChartInstance = new ApexCharts(chartElement, options);
        uptimeChartInstance.render();
    }

    // Initialize chart on first load
    $wire.on('chart-update', () => {
        initUptimeChart();
    });

    // Initialize on component load
    initUptimeChart();

    // Watch for Livewire updates
    Livewire.hook('commit', ({ component, commit, respond, succeed, fail }) => {
        succeed(({ snapshot, effect }) => {
            // Update chart after Livewire updates
            setTimeout(() => {
                initUptimeChart();
            }, 100);
        });
    });
</script>
@endscript