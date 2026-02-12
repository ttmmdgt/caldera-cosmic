<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Url;
use App\Models\InsBpmDevice;
use App\Services\BpmEmergencyService;
use App\Services\UptimeCalculatorService;
use App\Services\DurationFormatterService;
use Carbon\Carbon;

new class extends Component {
    #[Url] public $start_at;
    #[Url] public $plant = 'G';
    #[Url] public $line = '3';
    
    public $view = "dashboard";
    public $lastUpdated;
    public $emergencyByMachine = [];
    public $onlineStats = [];
    
    public function mount(): void
    {
        $this->dispatch('update-menu', $this->view);
        $this->start_at ??= now()->format('Y-m-d');
        $this->loadData();
    }
    
    public function updatedStartAt(): void
    {
        $this->loadAndRefresh();
    }
    
    public function updatedPlant(): void
    {
        $this->loadAndRefresh();
    }
    
    public function updatedLine(): void
    {
        $this->loadAndRefresh();
    }
    
    public function loadData(): void
    {
        $date = Carbon::parse($this->start_at);
        
        $this->emergencyByMachine = $this->loadEmergencyData($date);
        $this->onlineStats = $this->loadOnlineStats($date);
        $this->lastUpdated = now()->format('n/j/Y, H:i.s');
    }
    
    private function loadEmergencyData(Carbon $date): array
    {
        $service = app(BpmEmergencyService::class);
        return $service->getEmergencyDataByMachine($this->plant, $this->line, $date);
    }
    
    private function loadOnlineStats(Carbon $date): array
    {
        $device = $this->getActiveDevice();
        
        if (!$device) {
            return $this->getEmptyOnlineStats();
        }
        
        $projectName = $this->getProjectNameByIp($device->ip_address);
        
        if (!$projectName) {
            return $this->getEmptyOnlineStats();
        }
        
        $workingHours = config('bpm.working_hours');
        $start = $date->copy()->setTime($workingHours['start'], 0);
        $end = $date->copy()->setTime($workingHours['end'], 0);
        
        $calculator = app(UptimeCalculatorService::class);
        $stats = $calculator->calculateStats($projectName, $start, $end);
        
        $formatter = app(DurationFormatterService::class);
        
        return [
            'online_percentage' => $stats['online_percentage'],
            'offline_percentage' => $stats['offline_percentage'],
            'timeout_percentage' => $stats['timeout_percentage'],
            'online_time' => $formatter->format($stats['online_duration']),
            'offline_time' => $formatter->format($stats['offline_duration']),
            'timeout_time' => $formatter->format($stats['timeout_duration']),
        ];
    }
    
    private function getActiveDevice(): ?InsBpmDevice
    {
        return InsBpmDevice::where('is_active', true)
            ->where('line', $this->line)
            ->first();
    }
    
    private function getProjectNameByIp(string $ipAddress): ?string
    {
        $projects = config('uptime.projects', []);
        
        foreach ($projects as $name => $info) {
            if (($info['ip'] ?? null) === $ipAddress) {
                return $info['name'];
            }
        }
        
        return null;
    }
    
    private function getEmptyOnlineStats(): array
    {
        return [
            'online_percentage' => 0,
            'offline_percentage' => 0,
            'timeout_percentage' => 0,
            'online_time' => '0 seconds',
            'offline_time' => '0 seconds',
            'timeout_time' => '0 seconds',
        ];
    }
    
    private function loadAndRefresh(): void
    {
        $this->loadData();
        $this->refreshCharts();
    }
    
    public function refreshCharts(): void
    {
        $this->dispatch('refresh-charts', [
            'emergencyData' => $this->prepareEmergencyChartData(),
            'onlineData' => $this->prepareOnlineChartData(),
        ]);
    }
    
    private function prepareEmergencyChartData(): array
    {
        if (empty($this->emergencyByMachine)) {
            return ['labels' => [], 'datasets' => []];
        }
        
        $machines = collect($this->emergencyByMachine);
        
        return [
            'labels' => $machines->pluck('machine')->map(fn($m) => "Machine $m")->toArray(),
            'datasets' => [
                [
                    'label' => 'Hot',
                    'data' => $machines->pluck('hot')->toArray(),
                    'backgroundColor' => 'rgba(239, 68, 68, 0.8)',
                    'borderColor' => 'rgba(239, 68, 68, 1)',
                    'borderWidth' => 1,
                ],
                [
                    'label' => 'Cold',
                    'data' => $machines->pluck('cold')->toArray(),
                    'backgroundColor' => 'rgba(59, 130, 246, 0.8)',
                    'borderColor' => 'rgba(59, 130, 246, 1)',
                    'borderWidth' => 1,
                ]
            ]
        ];
    }
    
    private function prepareOnlineChartData(): array
    {
        return [
            'labels' => ['Online', 'Offline', 'Timeout (RTO)'],
            'datasets' => [[
                'data' => [
                    $this->onlineStats['online_percentage'] ?? 0,
                    $this->onlineStats['offline_percentage'] ?? 0,
                    $this->onlineStats['timeout_percentage'] ?? 0
                ],
                'backgroundColor' => [
                    'rgba(34, 197, 94, 0.8)',
                    'rgba(156, 163, 175, 0.8)',
                    'rgba(251, 146, 60, 0.8)',
                ],
                'borderColor' => [
                    'rgba(34, 197, 94, 1)',
                    'rgba(156, 163, 175, 1)',
                    'rgba(251, 146, 60, 1)',
                ],
                'borderWidth' => 1,
            ]]
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
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                    <option value="5">5</option>
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
    <div class="grid grid-cols-1 lg:grid-cols-6 gap-6 mb-6">
        {{-- Online System Monitoring (Pie Chart) --}}
        <div class="lg:col-span-2 bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
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
        <div class="lg:col-span-4 bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
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
                        },
                        datalabels: {
                            display: false
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
                        },
                        datalabels: {
                            display: false
                        }
                    },
                    scales: {
                        x: {
                            stacked: true,
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                callback: function(value) {
                                    if (Number.isInteger(value)) {
                                        return value.toLocaleString();
                                    }
                                    return '';
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
