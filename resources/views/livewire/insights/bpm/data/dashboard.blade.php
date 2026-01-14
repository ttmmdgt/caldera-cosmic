<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Url;
use App\Models\InsBpmCount;
use App\Models\InsBpmDevice;
use App\Models\UptimeLog;
use App\Traits\HasDateRangeFilter;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

new class extends Component {
    use HasDateRangeFilter;
    
    public $view = "dashboard";
    
    #[Url]
    public $start_at;
    
    #[Url]
    public $plant = 'G';
    
    #[Url]
    public $line = 'G5';
    
    public $lastUpdated;
    public $emergencyByMachine = [];
    public $onlineStats = [];

    public function mount()
    {
        $this->dispatch('update-menu', $this->view);
        
        // Set default date to today
        if (!$this->start_at) {
            $this->start_at = now()->format('Y-m-d');
        }
        
        // Load initial data
        $this->loadData();
    }
    
    public function updatedStartAt()
    {
        $this->loadData();
        $this->refreshCharts();
    }
    
    public function updatedPlant()
    {
        $this->loadData();
        $this->refreshCharts();
    }
    
    public function updatedLine()
    {
        $this->loadData();
        $this->refreshCharts();
    }

    public function loadData()
    {
        $date = Carbon::parse($this->start_at);
        $from = $date->copy()->startOfDay();
        $to = $date->copy()->endOfDay();

        // Get all records for the selected date and line
        $allRecords = InsBpmCount::whereBetween('created_at', [$from, $to])
            ->where('plant', $this->plant)
            ->where('line', $this->line)
            ->orderBy('created_at', 'desc')
            ->get();
        
        // Get latest records for each machine-condition combination
        $latestRecords = $allRecords->groupBy(function($item) {
            return $item->machine . '-' . $item->condition;
        })->map->first();
        
        // Prepare emergency by machine data for horizontal bar chart
        $machines = $latestRecords->pluck('machine')->unique()->sort()->values();
        
        $this->emergencyByMachine = $machines->map(function($machine) use ($latestRecords) {
            $hot = $latestRecords->where('machine', $machine)->where('condition', 'Hot')->first()->cumulative ?? 0;
            $cold = $latestRecords->where('machine', $machine)->where('condition', 'Cold')->first()->cumulative ?? 0;
            
            return [
                'machine' => $machine,
                'hot' => $hot,
                'cold' => $cold,
                'total' => $hot + $cold,
            ];
        })->sortByDesc('total')->values()->toArray();

        // Calculate online system monitoring stats from UptimeLog
        $devices = InsBpmDevice::where('is_active', true)
            ->where('line', $this->line)
            ->get();
        
        $totalDevices = $devices->count();
        $onlineCount  = 0;
        $offlineCount = 0;
        $timeoutCount = 0;
        $onlineDuration  = 0;
        $offlineDuration = 0;
        $timeoutDuration = 0;
        
        foreach ($devices as $device) {
            // Get the latest uptime log for this device
            $latestLog = UptimeLog::where('project_name', 'ins-bpm')
                ->where('ip_address', $device->ip_address)
                ->latest('checked_at')
                ->first();
            
            if ($latestLog) {
                if ($latestLog->status === 'online') {
                    $onlineCount++;
                    $onlineDuration += $latestLog->duration ?? 0;
                } elseif ($latestLog->status === 'offline') {
                    $offlineCount++;
                    $offlineDuration += $latestLog->duration ?? 0;
                } else {
                    $timeoutCount++;
                    $timeoutDuration += $latestLog->duration ?? 0;
                }
            } else {
                // No log found, consider offline
                $offlineCount++;
            }
        }
        
        $onlinePercentage = $totalDevices > 0 ? round(($onlineCount / $totalDevices) * 100, 1) : 0;
        $offlinePercentage = $totalDevices > 0 ? round(($offlineCount / $totalDevices) * 100, 1) : 0;
        $timeoutPercentage = $totalDevices > 0 ? round(($timeoutCount / $totalDevices) * 100, 1) : 0;
        
        // Format durations
        $onlineTime = $this->formatDuration($onlineDuration);
        $offlineTime = $this->formatDuration($offlineDuration);
        $timeoutTime = $this->formatDuration($timeoutDuration);
        
        $this->onlineStats = [
            'online_percentage' => $onlinePercentage,
            'offline_percentage' => $offlinePercentage,
            'timeout_percentage' => $timeoutPercentage,
            'online_time' => $onlineTime,
            'offline_time' => $offlineTime,
            'timeout_time' => $timeoutTime,
        ];

        $this->lastUpdated = now()->format('n/j/Y, H:i.s');
    }

    private function formatDuration($seconds)
    {
        if ($seconds === 0) {
            return '0 seconds';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours . ' hour' . ($hours > 1 ? 's' : '');
        }
        if ($minutes > 0) {
            $parts[] = $minutes . ' minute' . ($minutes > 1 ? 's' : '');
        }
        if ($secs > 0 || empty($parts)) {
            $parts[] = $secs . ' second' . ($secs > 1 ? 's' : '');
        }

        return implode(' ', $parts);
    }

    public function with(): array
    {
        return [
            'emergencyByMachine' => $this->emergencyByMachine,
            'onlineStats' => $this->onlineStats,
            'lastUpdated' => $this->lastUpdated,
        ];
    }

    public function refreshCharts()
    {
        $this->dispatch('refresh-charts', [
            'emergencyData' => $this->prepareEmergencyChartData(),
            'onlineData' => $this->prepareOnlineChartData(),
        ]);
    }

    private function prepareEmergencyChartData()
    {
        if (empty($this->emergencyByMachine)) {
            return [
                'labels' => [],
                'datasets' => []
            ];
        }
        
        return [
            'labels' => collect($this->emergencyByMachine)->pluck('machine')->map(fn($m) => 'Machine ' . $m)->toArray(),
            'datasets' => [
                [
                    'label' => 'Hot',
                    'data' => collect($this->emergencyByMachine)->pluck('hot')->toArray(),
                    'backgroundColor' => 'rgba(239, 68, 68, 0.8)',
                    'borderColor' => 'rgba(239, 68, 68, 1)',
                    'borderWidth' => 1,
                ],
                [
                    'label' => 'Cold',
                    'data' => collect($this->emergencyByMachine)->pluck('cold')->toArray(),
                    'backgroundColor' => 'rgba(59, 130, 246, 0.8)',
                    'borderColor' => 'rgba(59, 130, 246, 1)',
                    'borderWidth' => 1,
                ]
            ]
        ];
    }

    private function prepareOnlineChartData()
    {
        return [
            'labels' => ['Online', 'Offline', 'Timeout (RTO)'],
            'datasets' => [
                [
                    'data' => [
                        $this->onlineStats['online_percentage'] ?? 0,
                        $this->onlineStats['offline_percentage'] ?? 0,
                        $this->onlineStats['timeout_percentage'] ?? 0
                    ],
                    'backgroundColor' => [
                        'rgba(34, 197, 94, 0.8)', // green
                        'rgba(156, 163, 175, 0.8)', // gray
                        'rgba(251, 146, 60, 0.8)', // orange
                    ],
                    'borderColor' => [
                        'rgba(34, 197, 94, 1)',
                        'rgba(156, 163, 175, 1)',
                        'rgba(251, 146, 60, 1)',
                    ],
                    'borderWidth' => 1,
                ]
            ]
        ];
    }
}; ?>

<div>
    {{-- Header with Filters --}}
    <div class="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
        <div class="flex flex-col sm:flex-row gap-4 items-start sm:items-end flex-1">
            <div>
                <label class="block text-sm font-medium mb-2 text-gray-700 dark:text-gray-300">{{ __('DATE') }}</label>
                <x-text-input wire:model.live="start_at" type="date" class="w-40" />
            </div>
            <div>
                <label class="block text-sm font-medium mb-2 text-gray-700 dark:text-gray-300">{{ __('PLANT') }}</label>
                <select wire:model.live="plant" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm">
                    <option value="A">A</option>
                    <option value="B">B</option>
                    <option value="C">C</option>
                    <option value="D">D</option>
                    <option value="E">E</option>
                    <option value="F">F</option>
                    <option value="G">G</option>
                    <option value="H">H</option>
                    <option value="I">I</option>
                    <option value="J">J</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-2 text-gray-700 dark:text-gray-300">{{ __('LINE') }}</label>
                <select wire:model.live="line" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm">
                    <option value="G1">G1</option>
                    <option value="G2">G2</option>
                    <option value="G3">G3</option>
                    <option value="G4">G4</option>
                    <option value="G5">G5</option>
                </select>
            </div>
        </div>
        <div class="text-sm">
            <div class="text-red-500 font-medium">{{ __('Data counter update every 5 min') }}</div>
            <div class="text-gray-600 dark:text-gray-400 mt-1">
                <span class="text-xs">{{ __('Last Updated') }}</span><br>
                <span class="font-semibold">{{ $lastUpdated }}</span>
            </div>
        </div>
    </div>

    {{-- Main Content Grid --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        {{-- Online System Monitoring (Pie Chart) --}}
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4 text-gray-800 dark:text-gray-200">{{ __('Online System Monitoring') }}</h2>
            <div class="flex flex-col items-center">
                <div class="w-64 h-64 mb-4">
                    <canvas id="onlineChart" wire:ignore></canvas>
                </div>
                <div class="w-full space-y-2">
                    <div class="flex items-center justify-between p-2 bg-green-50 dark:bg-green-900/20 rounded">
                        <div class="flex items-center gap-2">
                            <div class="w-4 h-4 rounded-full bg-green-500"></div>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Online') }}</span>
                        </div>
                        <div class="text-right">
                            <div class="font-semibold text-green-600 dark:text-green-400">{{ $onlineStats['online_percentage'] ?? 0 }}%</div>
                            <div class="text-xs text-gray-500">{{ $onlineStats['online_time'] ?? '0 seconds' }}</div>
                        </div>
                    </div>
                    <div class="flex items-center justify-between p-2 bg-gray-50 dark:bg-gray-900/20 rounded">
                        <div class="flex items-center gap-2">
                            <div class="w-4 h-4 rounded-full bg-gray-400"></div>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Offline') }}</span>
                        </div>
                        <div class="text-right">
                            <div class="font-semibold text-gray-600 dark:text-gray-400">{{ $onlineStats['offline_percentage'] ?? 0 }}%</div>
                            <div class="text-xs text-gray-500">{{ $onlineStats['offline_time'] ?? '0 seconds' }}</div>
                        </div>
                    </div>
                    <div class="flex items-center justify-between p-2 bg-orange-50 dark:bg-orange-900/20 rounded">
                        <div class="flex items-center gap-2">
                            <div class="w-4 h-4 rounded-full bg-orange-500"></div>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Timeout (RTO)') }}</span>
                        </div>
                        <div class="text-right">
                            <div class="font-semibold text-orange-600 dark:text-orange-400">{{ $onlineStats['timeout_percentage'] ?? 0 }}%</div>
                            <div class="text-xs text-gray-500">{{ $onlineStats['timeout_time'] ?? '0 seconds' }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Emergency Counter - Horizontal Bar Chart --}}
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200">{{ __('Emergency Counter - Line') }} {{ $line }}</h2>
                <p class="text-sm text-gray-500">{{ __('SORT') }}</p>
            </div>
            <div class="h-96">
                <canvas id="emergencyChart" wire:ignore></canvas>
            </div>
        </div>
    </div>
</div>

@script
<script>
    let onlineChart, emergencyChart;

    function initCharts(onlineData, emergencyData) {
        // Online System Monitoring - Pie Chart
        const onlineCtx = document.getElementById('onlineChart');
        
        if (onlineCtx) {
            const existingOnlineChart = Chart.getChart(onlineCtx);
            if (existingOnlineChart) {
                existingOnlineChart.destroy();
            }
            
            onlineChart = new Chart(onlineCtx, {
                type: 'doughnut',
                data: onlineData,
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { 
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': ' + context.parsed + '%';
                                }
                            }
                        }
                    }
                }
            });
        }

        // Emergency Counter - Horizontal Bar Chart
        const emergencyCtx = document.getElementById('emergencyChart');
        
        if (emergencyCtx) {
            const existingEmergencyChart = Chart.getChart(emergencyCtx);
            if (existingEmergencyChart) {
                existingEmergencyChart.destroy();
            }
            
            emergencyChart = new Chart(emergencyCtx, {
                type: 'bar',
                data: emergencyData,
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            display: true,
                            position: 'top'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.x + ' counts';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            stacked: true,
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString();
                                }
                            }
                        },
                        y: {
                            stacked: true
                        }
                    }
                }
            });
        }
    }

    // Listen for refresh event
    Livewire.on('refresh-charts', (event) => {
        const data = event[0] || event;
        initCharts(data.onlineData, data.emergencyData);
    });

    // Initial load
    const initializeCharts = () => {
        const onlineData = @json($this->prepareOnlineChartData());
        const emergencyData = @json($this->prepareEmergencyChartData());
        
        // Wait for DOM to be ready
        if (document.getElementById('onlineChart') && document.getElementById('emergencyChart')) {
            initCharts(onlineData, emergencyData);
        } else {
            // Retry after a short delay if elements not found
            setTimeout(initializeCharts, 100);
        }
    };

    // Run after Livewire component is loaded
    initializeCharts();
</script>
@endscript
