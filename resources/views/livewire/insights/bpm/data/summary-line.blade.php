<?php

use Livewire\Volt\Component;
use App\Models\InsBpmCount;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Livewire\Attributes\Url;

new class extends Component {
    public $view = "summary-line";
    
    #[Url]
    public $dateFrom;
    
    #[Url]
    public $dateTo;
    
    #[Url]
    public $plant = 'G';
    
    #[Url]
    public $line = '1';
    
    public $lastUpdated;
    public $summaryCards = [];
    public $rankingData = [];
    public $chartData = [];
    public $trendChartData = [];
    public $emergencyByMachine = [];
    public $trendByHour = [];

    public function mount()
    {
        $this->dispatch('update-menu', 'summary-line');
        
        // Set default dates - last 7 days
        $this->dateFrom = now()->subDays(6)->format('Y-m-d');
        $this->dateTo = now()->format('Y-m-d');
        
        // Load initial data
        $this->loadData();
        $this->refreshCharts();
    }
    
    public function updatedDateFrom()
    {
        $this->loadData();
        $this->refreshCharts();
    }
    
    public function updatedDateTo()
    {
        $this->loadData();
        $this->refreshCharts();
    }
    
    public function updatedLine()
    {
        $this->loadData();
        $this->refreshCharts();
    }
    
    public function updatedPlant()
    {
        $this->loadData();
        $this->refreshCharts();
    }

    public function loadData()
    {
        $from = Carbon::parse($this->dateFrom)->startOfDay();
        $to = Carbon::parse($this->dateTo)->endOfDay();

        // Total Emergency for selected plant and line
        $totalEmergency = InsBpmCount::whereBetween('created_at', [$from, $to])
            ->where('plant', $this->plant)
            ->where('line', $this->line)
            ->sum('incremental');

        // Emergency by machine for selected plant and line
        $emergencyByMachine = InsBpmCount::whereBetween('created_at', [$from, $to])
            ->where('plant', $this->plant)
            ->where('line', $this->line)
            ->select('machine', DB::raw('SUM(incremental) as total'))
            ->groupBy('machine')
            ->orderByDesc('total')
            ->get();

        // Find highest and lowest
        $highest = $emergencyByMachine->first();
        $lowest = $emergencyByMachine->last();
        $average = $emergencyByMachine->count() > 0 
            ? round($emergencyByMachine->avg('total')) 
            : 0;

        // Summary Cards
        $this->summaryCards = [
            [
                'label' => 'TOTAL EMERGENCY',
                'sublabel' => 'Plant ' . $this->plant . ' Line ' . $this->line . ' - Semua Mesin',
                'value' => $totalEmergency,
                'color' => 'red',
            ],
            [
                'label' => 'TERTINGGI',
                'sublabel' => $highest ? $highest->machine : '-',
                'value' => $highest ? $highest->total : 0,
                'color' => 'red',
            ],
            [
                'label' => 'TERENDAH',
                'sublabel' => $lowest ? $lowest->machine : '-',
                'value' => $lowest ? $lowest->total : 0,
                'color' => 'green',
            ],
            [
                'label' => 'RATA-RATA PER MESIN',
                'sublabel' => 'Emergency/Mesin',
                'value' => $average,
                'color' => 'blue',
            ],
        ];

        // Emergency by machine data for bar chart
        $maxTotal = $emergencyByMachine->max('total');
        $this->emergencyByMachine = $emergencyByMachine->map(function ($item) use ($maxTotal) {
            return [
                'machine' => $item->machine,
                'total' => $item->total,
                'color' => $this->getColorForValue($item->total, $maxTotal)
            ];
        })->toArray();

        // Trend by hour
        $this->loadTrendData($from, $to);

        // Ranking data
        $this->rankingData = $emergencyByMachine->take(10)->map(function ($item, $index) {
            return [
                'rank' => $index + 1,
                'machine' => $item->machine,
                'line' => $this->plant . $this->line,
                'counter' => $item->total,
            ];
        })->toArray();

        $this->lastUpdated = now()->format('n/j/Y, H:i.s');
    }

    private function getColorForValue($value, $max)
    {
        if ($max == 0) return 'rgba(34, 197, 94, 0.8)'; // green
        
        $percentage = ($value / $max) * 100;
        
        if ($percentage >= 80) return 'rgba(239, 68, 68, 0.8)'; // red
        if ($percentage >= 60) return 'rgba(251, 146, 60, 0.8)'; // orange
        if ($percentage >= 40) return 'rgba(234, 179, 8, 0.8)'; // yellow
        return 'rgba(34, 197, 94, 0.8)'; // green
    }

    private function loadTrendData($from, $to)
    {
        // Get hourly data
        $hourlyData = InsBpmCount::whereBetween('created_at', [$from, $to])
            ->where('plant', $this->plant)
            ->where('line', $this->line)
            ->select(
                'machine',
                DB::raw('HOUR(created_at) as hour'),
                DB::raw('SUM(incremental) as total')
            )
            ->groupBy('machine', 'hour')
            ->orderBy('hour')
            ->get();

        // Get all machines
        $machines = $hourlyData->pluck('machine')->unique()->sort()->values();

        // Prepare data for chart
        $datasets = [];
        $colors = [
            'rgba(239, 68, 68, 1)',   // red
            'rgba(251, 146, 60, 1)',  // orange
            'rgba(234, 179, 8, 1)',   // yellow
            'rgba(34, 197, 94, 1)',   // green
        ];

        foreach ($machines as $index => $machine) {
            $machineData = [];
            for ($hour = 6; $hour <= 17; $hour++) {
                $value = $hourlyData->where('machine', $machine)
                    ->where('hour', $hour)
                    ->first();
                $machineData[] = $value ? $value->total : null;
            }

            $datasets[] = [
                'label' => $machine,
                'data' => $machineData,
                'borderColor' => $colors[$index % count($colors)],
                'backgroundColor' => str_replace('1)', '0.1)', $colors[$index % count($colors)]),
                'tension' => 0.4,
            ];
        }

        $this->trendChartData = [
            'labels' => collect(range(6, 17))->map(fn($h) => sprintf('%02d:00', $h))->toArray(),
            'datasets' => !empty($datasets) ? $datasets : [[
                'label' => 'No Data',
                'data' => array_fill(0, 12, 0),
                'borderColor' => 'rgba(200, 200, 200, 1)',
                'backgroundColor' => 'rgba(200, 200, 200, 0.1)',
                'tension' => 0.4,
            ]],
        ];

        // Calculate trend metrics
        $morningCount = $hourlyData->whereBetween('hour', [7, 11])->sum('total');
        $afternoonCount = $hourlyData->whereBetween('hour', [12, 17])->sum('total');
        
        // Find peak hour
        $peakHourData = $hourlyData->groupBy('hour')
            ->map(fn($items) => $items->sum('total'))
            ->sortDesc()
            ->first();
        
        $peakHour = $hourlyData->groupBy('hour')
            ->map(fn($items) => $items->sum('total'))
            ->sortDesc()
            ->keys()
            ->first();

        $this->trendByHour = [
            'peak_hour' => $peakHour !== null ? $peakHour : 0,
            'peak_count' => $peakHourData ?? 0,
            'morning_count' => $morningCount,
            'afternoon_count' => $afternoonCount,
        ];
    }

    public function with(): array
    {
        return [
            'summaryCards' => $this->summaryCards,
            'rankingData' => $this->rankingData,
            'lastUpdated' => $this->lastUpdated,
            'emergencyByMachine' => $this->emergencyByMachine,
            'trendChartData' => $this->trendChartData,
            'trendByHour' => $this->trendByHour,
        ];
    }

    public function refreshCharts()
    {
        $this->dispatch('refresh-charts', [
            'barChartData' => $this->prepareBarChartData(),
            'trendChartData' => $this->trendChartData,
        ]);
    }

    private function prepareBarChartData()
    {
        if (empty($this->emergencyByMachine)) {
            return [
                'labels' => [],
                'datasets' => [
                    [
                        'label' => 'Emergency Counter',
                        'data' => [],
                        'backgroundColor' => [],
                        'borderColor' => [],
                        'borderWidth' => 1,
                    ]
                ]
            ];
        }
        
        return [
            'labels' => collect($this->emergencyByMachine)->pluck('machine')->toArray(),
            'datasets' => [
                [
                    'label' => 'Emergency Counter',
                    'data' => collect($this->emergencyByMachine)->pluck('total')->toArray(),
                    'backgroundColor' => collect($this->emergencyByMachine)->pluck('color')->toArray(),
                    'borderColor' => collect($this->emergencyByMachine)->map(fn($item) => str_replace('0.8', '1', $item['color']))->toArray(),
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
                <label class="block text-sm font-medium mb-2">{{ __('RENTANG') }}</label>
                <div class="flex gap-2 items-center">
                    <input type="date" wire:model.live="dateFrom" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm">
                    <span class="text-gray-500">-</span>
                    <input type="date" wire:model.live="dateTo" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-2">{{ __('LINE') }}</label>
                <select wire:model.live="line" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm">
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                    <option value="5">5</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-2">{{ __('PLANT') }}</label>
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
        </div>
        <div class="text-sm text-gray-600 dark:text-gray-400">
            <div>{{ __('Last Updated') }}</div>
            <div class="font-semibold">{{ $lastUpdated }}</div>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        @foreach($summaryCards as $card)
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6 border-l-4 border-{{ $card['color'] }}-500">
            <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">
                {{ $card['label'] }}
            </div>
            <div class="text-2xl font-bold text-{{ $card['color'] }}-600 dark:text-{{ $card['color'] }}-400 mb-1">
                {{ number_format($card['value']) }}
            </div>
            <div class="text-xs text-gray-600 dark:text-gray-400">
                {{ $card['sublabel'] }}
            </div>
        </div>
        @endforeach
    </div>

    {{-- Main Content Grid --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        {{-- Bar Chart: Emergency Counter by Machine --}}
        <div class="lg:col-span-2 bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
            <div class="mb-4">
                <h2 class="text-lg font-semibold">{{ __('Emergency Counter - Line') }} {{ $plant }}{{ $line }}</h2>
                <p class="text-sm text-gray-500">{{ __('SORT') }}</p>
            </div>
            <div class="h-96">
                <canvas id="barChart" wire:ignore></canvas>
            </div>
        </div>

        {{-- Summary Metrics --}}
        <div class="space-y-6">
            {{-- Trend Emergency per Jam --}}
            <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
                <h3 class="text-base font-semibold mb-4">{{ __('Trend Emergency per Jam') }}</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div class="border border-red-500 rounded-lg p-4">
                        <div class="text-xs text-gray-500 uppercase mb-1">{{ __('JAM TERTINGGI') }}</div>
                        <div class="text-3xl font-bold text-red-600">{{ $trendByHour['peak_hour'] ?? 0 }}</div>
                        <div class="text-xs text-gray-500">{{ $trendByHour['peak_hour'] ?? 0 }} AM</div>
                    </div>
                    <div class="border border-green-500 rounded-lg p-4">
                        <div class="text-xs text-gray-500 uppercase mb-1">{{ __('JAM TERENDAH') }}</div>
                        <div class="text-3xl font-bold text-green-600">{{ $trendByHour['afternoon_count'] > 0 ? 12 : 0 }}</div>
                        <div class="text-xs text-gray-500">12 PM</div>
                    </div>
                    <div class="col-span-2 border border-blue-500 rounded-lg p-4">
                        <div class="text-xs text-gray-500 uppercase mb-1">{{ __('RATA-RATA PER JAM') }}</div>
                        <div class="text-3xl font-bold text-blue-600">{{ number_format(($trendByHour['peak_count'] ?? 0) / 24) }}</div>
                        <div class="text-xs text-gray-500">Emergency/Jam</div>
                    </div>
                </div>
            </div>

            {{-- Ranking Emergency Counter --}}
            <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
                <h3 class="text-base font-semibold mb-4">{{ __('Ranking Emergency Counter') }}</h3>
                <div class="space-y-2">
                    @forelse($rankingData as $item)
                    <div class="flex items-center justify-between p-3 rounded-lg {{ $item['rank'] == 1 ? 'bg-red-50 dark:bg-red-900/20' : ($item['rank'] == 2 ? 'bg-orange-50 dark:bg-orange-900/20' : ($item['rank'] == 3 ? 'bg-yellow-50 dark:bg-yellow-900/20' : 'bg-green-50 dark:bg-green-900/20')) }}">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold {{ $item['rank'] == 1 ? 'bg-red-500 text-white' : ($item['rank'] == 2 ? 'bg-orange-500 text-white' : ($item['rank'] == 3 ? 'bg-yellow-500 text-white' : 'bg-green-500 text-white')) }}">
                                {{ $item['rank'] }}
                            </div>
                            <div>
                                <div class="font-semibold text-sm">{{ __('Line') }} {{ $item['line'] }}</div>
                                <div class="text-xs text-gray-500">{{ $item['machine'] }}</div>
                            </div>
                        </div>
                        <div class="text-lg font-bold {{ $item['rank'] == 1 ? 'text-red-600' : ($item['rank'] == 2 ? 'text-orange-600' : ($item['rank'] == 3 ? 'text-yellow-600' : 'text-green-600')) }}">
                            {{ number_format($item['counter']) }}
                        </div>
                    </div>
                    @empty
                    <div class="text-center py-8 text-gray-500">
                        {{ __('Tidak ada data') }}
                    </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Line Chart: Incremental Emergency Counter (6 AM - 5 PM) --}}
    <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
        <div class="mb-4">
            <h2 class="text-lg font-semibold">{{ __('Incremental Emergency Counter (6 AM - 5 PM)') }}</h2>
        </div>
        <div class="h-96">
            <canvas id="trendChart" wire:ignore></canvas>
        </div>
        <div class="mt-4 flex justify-center gap-6">
            @foreach($trendChartData['datasets'] ?? [] as $dataset)
            <div class="flex items-center gap-2">
                <div class="w-4 h-0.5" style="background-color: {{ $dataset['borderColor'] }}"></div>
                <span class="text-sm">{{ $dataset['label'] }}</span>
            </div>
            @endforeach
        </div>
    </div>
</div>

@script
<script>
    let barChart, trendChart;

    function initCharts(barData, trendData) {
        // Bar Chart
        const barCtx = document.getElementById('barChart');
        if (barChart) barChart.destroy();
        
        if (barCtx) {
            barChart = new Chart(barCtx, {
                type: 'bar',
                data: barData,
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.parsed.x + ' counts';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        }

        // Trend Chart (Line Chart)
        const trendCtx = document.getElementById('trendChart');
        if (trendChart) trendChart.destroy();
        
        if (trendCtx) {
            trendChart = new Chart(trendCtx, {
                type: 'line',
                data: trendData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + (context.parsed.y ?? 0) + ' counts';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            display: true,
                            title: { 
                                display: true, 
                                text: 'Hour'
                            }
                        },
                        y: {
                            display: true,
                            title: { 
                                display: true, 
                                text: 'Counter'
                            },
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    // Listen for refresh event
    Livewire.on('refresh-charts', (event) => {
        const data = event[0] || event;
        initCharts(data.barChartData, data.trendChartData);
    });

    // Initial load
    const initializeCharts = () => {
        const barData = @json($this->prepareBarChartData());
        const trendData = @json($trendChartData);
        
        // Wait for DOM to be ready
        if (document.getElementById('barChart') && document.getElementById('trendChart')) {
            initCharts(barData, trendData);
        } else {
            // Retry after a short delay if elements not found
            setTimeout(initializeCharts, 100);
        }
    };

    // Run after Livewire component is loaded
    initializeCharts();
</script>
@endscript
