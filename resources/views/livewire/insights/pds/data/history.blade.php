<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use App\Models\InsPhDosingCount;
use App\Models\InsPhDosingDevice;
use App\Traits\HasDateRangeFilter;
use App\Models\InsPhDosingLog;
use Carbon\Carbon;

new class extends Component {
    use WithPagination;
    use HasDateRangeFilter;

    #[Url]
    public string $start_at = "";

    #[Url]
    public string $end_at = "";

    #[Url]
    public string $plant = "1";

    public int $perPage = 20;
    public $view = "history";

    public function mount()
    {
        if (! $this->start_at || ! $this->end_at) {
            $this->setToday();
            $this->start_at = Carbon::today()->toDateString();
            $this->end_at   = Carbon::today()->toDateString();
        } else {
            $this->start_at = Carbon::parse($this->start_at)->toDateString();
            $this->end_at   = Carbon::parse($this->end_at)->toDateString();
        }
        $this->dispatch("update-menu", $this->view);
    }

    public function updated($propertyName)
    {
        // Dispatch event to update chart when filters change
        if (in_array($propertyName, ['start_at', 'end_at', 'plant'])) {
            $this->dispatch('chart-data-updated', chartData: $this->getChartData());
        }
    }
    
    public function getStatistics()
    {
        $dataLog = InsPhDosingLog::with('device')
            ->whereBetween("created_at", [Carbon::parse($this->start_at)->startOfDay(), Carbon::parse($this->end_at)->endOfDay()]);

        $dataPh = InsPhDosingCount::with('device')
            ->whereBetween("created_at", [Carbon::parse($this->start_at)->startOfDay(), Carbon::parse($this->end_at)->endOfDay()]);
        
        if ($this->plant) {
            $dataLog->whereHas('device', function($q) {
                $q->where('id', $this->plant);
            });
            $dataPh->whereHas('device', function($q) {
                $q->where('id', $this->plant);
            });
        }
        
        $dataPh = $dataPh->orderBy("created_at", "ASC")->get();
        $dataLog = $dataLog->orderBy("created_at", "ASC")->get();

        // Group data by 5-minute intervals
        $fiveMinuteStats = [];
        
        // Process pH data - same approach as getChartData()
        foreach ($dataPh as $count) {
            $timestamp = Carbon::parse($count->created_at);
            
            // Round to nearest 5-minute interval
            $minute = $timestamp->minute;
            $roundedMinute = floor($minute / 5) * 5;
            $roundedTimestamp = $timestamp->copy()->second(0)->minute($roundedMinute);
            
            // Create 5-minute interval key (format: Y-m-d H:i:00)
            $intervalKey = $roundedTimestamp->format('Y-m-d H:i:00');
            
            // Extract ph value from the ph_value array/json
            // Handle both seeder format ('current') and polling format ('current_ph')
            if (is_array($count->ph_value)) {
                $phValue = $count->ph_value['current_ph'] ?? $count->ph_value['current'] ?? 0;
            } else {
                $phValue = 0;
            }
            
            // Initialize interval if not exists
            if (!isset($fiveMinuteStats[$intervalKey])) {
                $fiveMinuteStats[$intervalKey] = [
                    'timestamp' => $roundedTimestamp,
                    'ph_sum' => 0,
                    'ph_count' => 0,
                    'ph_max' => 0,
                    'ph_min' => PHP_FLOAT_MAX,
                    'dosing_amounts' => [],
                    'dosing_count' => 0,
                ];
            }
            
            // Accumulate pH values using sum/count approach
            $phFloat = (float) $phValue;
            $fiveMinuteStats[$intervalKey]['ph_sum'] += $phFloat;
            $fiveMinuteStats[$intervalKey]['ph_count']++;
            
            // Track min/max pH
            if ($phFloat > $fiveMinuteStats[$intervalKey]['ph_max']) {
                $fiveMinuteStats[$intervalKey]['ph_max'] = $phFloat;
            }
            if ($phFloat < $fiveMinuteStats[$intervalKey]['ph_min']) {
                $fiveMinuteStats[$intervalKey]['ph_min'] = $phFloat;
            }
        }
        
        // Process dosing log data
        foreach ($dataLog as $log) {
            $timestamp = Carbon::parse($log->created_at);
            
            // Round to nearest 5-minute interval
            $minute = $timestamp->minute;
            $roundedMinute = floor($minute / 5) * 5;
            $roundedTimestamp = $timestamp->copy()->second(0)->minute($roundedMinute);
            
            // Create 5-minute interval key
            $intervalKey = $roundedTimestamp->format('Y-m-d H:i:00');
            
            // Initialize interval if not exists
            if (!isset($fiveMinuteStats[$intervalKey])) {
                $fiveMinuteStats[$intervalKey] = [
                    'timestamp' => $roundedTimestamp,
                    'ph_sum' => 0,
                    'ph_count' => 0,
                    'ph_max' => 0,
                    'ph_min' => PHP_FLOAT_MAX,
                    'dosing_amounts' => [],
                    'dosing_count' => 0,
                ];
            }
            
            $fiveMinuteStats[$intervalKey]['dosing_amounts'][] = $log->dosing_amount;
            $fiveMinuteStats[$intervalKey]['dosing_count']++;
        }
        
        // Calculate statistics for each interval and overall
        $intervalStats = [];
        $totalDossing = 0;
        $dossingCount = 0;
        $highestPh = 0;
        $lowestPh = PHP_FLOAT_MAX;
        
        ksort($fiveMinuteStats);
        
        foreach ($fiveMinuteStats as $intervalKey => $data) {
            // Calculate average pH using sum/count approach (same as getChartData)
            $avgPh = $data['ph_count'] > 0 
                ? $data['ph_sum'] / $data['ph_count'] 
                : 0;
            
            $intervalDosing = !empty($data['dosing_amounts']) 
                ? array_sum($data['dosing_amounts']) 
                : 0;
            
            $maxPhInInterval = $data['ph_max'];
            $minPhInInterval = $data['ph_min'] !== PHP_FLOAT_MAX ? $data['ph_min'] : 0;
            
            // Track overall statistics
            $totalDossing += $intervalDosing;
            $dossingCount += $data['dosing_count'];
            
            // Track highest and lowest AVERAGE pH (to match chart display)
            if ($data['ph_count'] > 0) {
                if ($avgPh > $highestPh) {
                    $highestPh = $avgPh;
                }
                
                if ($avgPh < $lowestPh) {
                    $lowestPh = $avgPh;
                }
            }
            
            // Store interval statistics
            $intervalStats[] = [
                'interval' => $intervalKey,
                'timestamp' => $data['timestamp']->format('Y-m-d H:i:s'),
                'avg_ph' => round($avgPh, 2),
                'max_ph' => round($maxPhInInterval, 2),
                'min_ph' => round($minPhInInterval, 2),
                'total_dosing' => $intervalDosing,
                'dosing_count' => $data['dosing_count'],
            ];
        }
        
        // Set lowest pH to 0 if no data
        if ($lowestPh === PHP_FLOAT_MAX) {
            $lowestPh = 0;
        }
        
        return [
            'total_dossing' => $totalDossing,
            'dossing_count' => $dossingCount,
            'highest_ph' => round($highestPh, 2),
            'lowest_ph' => round($lowestPh, 1),
            'intervals' => $intervalStats,
        ];
    }

    function getUniquePlants()
    {
        return InsPhDosingDevice::orderBy("plant")
            ->get()
            ->pluck("plant", "id")
            ->toArray();
    }

    public function getCountsQuery()
    {
        $query = InsPhDosingCount::with('device')
            ->whereBetween("created_at", [$this->start_at, $this->end_at]);

        if ($this->plant) {
            $query->whereHas('device', function($q) {
                $q->where('id', $this->plant);
            });
        }

        return $query->orderBy("created_at", "DESC");
    }

    public function getChartData()
    {
        $data = InsPhDosingCount::with('device')
            ->whereBetween("created_at", [Carbon::parse($this->start_at)->startOfDay(), Carbon::parse($this->end_at)->endOfDay()]);

        if ($this->plant) {
            $data->whereHas('device', function($q) {
                $q->where('id', $this->plant);
            });
        }

        $data = $data->orderBy("created_at", "ASC")->get();

        // Group data by 5-minute intervals
        $fiveMinuteData = [];
        
        foreach ($data as $count) {
            $timestamp = Carbon::parse($count->created_at);
            
            // Round to nearest 5-minute interval
            $minute = $timestamp->minute;
            $roundedMinute = floor($minute / 5) * 5;
            $roundedTimestamp = $timestamp->copy()->second(0)->minute($roundedMinute);
            
            // Create 5-minute interval key (format: Y-m-d H:i:00)
            $intervalKey = $roundedTimestamp->format('Y-m-d H:i:00');
            
            // Extract ph value from the ph_value array/json
            // Handle both seeder format ('current') and polling format ('current_ph')
            if (is_array($count->ph_value)) {
                $phValue = $count->ph_value['current_ph'] ?? $count->ph_value['current'] ?? 0;
            } else {
                $phValue = 0;
            }
            
            // Initialize 5-minute interval group if not exists
            if (!isset($fiveMinuteData[$intervalKey])) {
                $fiveMinuteData[$intervalKey] = [
                    'sum' => 0,
                    'count' => 0,
                    'timestamp' => $roundedTimestamp
                ];
            }
            
            // Accumulate pH values
            $fiveMinuteData[$intervalKey]['sum'] += (float) $phValue;
            $fiveMinuteData[$intervalKey]['count']++;
        }

        // Calculate averages and prepare chart data
        $chartData = [
            'labels' => [],
            'phValues' => [],
        ];

        // Determine if we need to show dates in labels (multi-day range)
        $startDate = Carbon::parse($this->start_at);
        $endDate = Carbon::parse($this->end_at);
        $showDate = $startDate->diffInDays($endDate) > 0;

        // Sort by interval key and build final arrays
        ksort($fiveMinuteData);
        
        foreach ($fiveMinuteData as $intervalKey => $intervalInfo) {
            $avgPh = $intervalInfo['count'] > 0 ? $intervalInfo['sum'] / $intervalInfo['count'] : 0;
            
            // Format label based on date range
            if ($showDate) {
                $chartData['labels'][] = $intervalInfo['timestamp']->format('d/m H:i');
            } else {
                $chartData['labels'][] = $intervalInfo['timestamp']->format('H:i');
            }
            
            $chartData['phValues'][] = round($avgPh, 2);
        }

        return $chartData;
    }

    public function with(): array
    {
        return [
            'counts' => $this->getCountsQuery()->paginate($this->perPage),
            'chartData' => $this->getChartData(),
            'statistics' => $this->getStatistics(),
        ];
    }

}; ?>

<div>
    <div class="p-0 sm:p-1 mb-6">
        <div class="flex flex-col lg:flex-row gap-3 w-full bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-4">
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
                    <x-text-input wire:model.live="start_at" type="date" class="w-40" />
                    <x-text-input wire:model.live="end_at" type="date" class="w-40" />
                </div>
            </div>
            <div class="border-l border-neutral-300 dark:border-neutral-700 mx-2"></div>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Plant") }}</label>
                    <x-select wire:model.live="plant" class="w-full">
                        <option value="">{{ __("Semua") }}</option>
                        @foreach($this->getUniquePlants() as $id => $plantOption)
                            <option value="{{$id}}">{{$plantOption}}</option>
                        @endforeach
                    </x-select>
                </div>
            </div>
            <div class="border-l border-neutral-300 dark:border-neutral-700 mx-2"></div>
            <div class="grow flex justify-between gap-x-2 items-center">
                <div>
                    <div class="px-3">
                        <div wire:loading.class="hidden">{{ $counts->total() . " " . __("entri") }}</div>
                        <div wire:loading.class.remove="hidden" class="hidden">{{ __("Memuat...") }}</div>
                    </div>
                </div>
                <div class="flex gap-x-2">
                    <x-dropdown align="right" width="48">
                        <x-slot name="trigger">
                            <x-text-button><i class="icon-ellipsis-vertical"></i></x-text-button>
                        </x-slot>
                        <x-slot name="content">
                            <x-dropdown-link href="#" wire:click.prevent="download('counts')">
                                <i class="icon-download me-2"></i>
                                {{ __("CSV Data") }}
                            </x-dropdown-link>
                        </x-slot>
                    </x-dropdown>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mt-2">
            <!-- Left Side: Chart Area -->
            <div class="lg:col-span-2 bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
                <div 
                    x-data="{ 
                        chartData: @js($chartData),
                        chart: null,
                        initChart() {
                            if (typeof ApexCharts === 'undefined') {
                                setTimeout(() => this.initChart(), 100);
                                return;
                            }
                            this.renderChart(this.chartData);
                        },
                        renderChart(data) {
                            const chartElement = this.$refs.chartContainer;
                            if (!chartElement || !data || !data.labels || !data.phValues) return;
                            
                            if (this.chart) {
                                this.chart.destroy();
                            }
                            
                            const phSeries = data.phValues || [];
                            const labels = data.labels || [];
                            const maxLimit = Array(labels.length).fill(3);
                            const minLimit = Array(labels.length).fill(2);
                            
                            const isDark = document.documentElement.classList.contains('dark');
                            const textColor = isDark ? '#d4d4d4' : '#525252';
                            const gridColor = isDark ? '#404040' : '#e5e7eb';
                            
                            const options = {
                                series: [
                                    { name: 'Nilai pH', data: phSeries, type: 'line' },
                                    { name: 'Batas Maksimal (pH 3)', data: maxLimit, type: 'line' },
                                    { name: 'Batas Minimal (pH 2)', data: minLimit, type: 'line' }
                                ],
                                chart: {
                                    height: 350,
                                    type: 'line',
                                    toolbar: {
                                        show: true,
                                        tools: { download: true, selection: false, zoom: true, zoomin: true, zoomout: true, pan: false, reset: true }
                                    },
                                    animations: { enabled: true, easing: 'easeinout', speed: 800 },
                                    background: 'transparent'
                                },
                                colors: ['#3b82f6', '#ef4444', '#ef4444'],
                                stroke: { width: [3, 2, 2], curve: 'smooth', dashArray: [0, 5, 5] },
                                markers: {
                                    size: [5, 0, 0],
                                    colors: ['#3b82f6', '#ef4444', '#ef4444'],
                                    strokeColors: '#fff',
                                    strokeWidth: 2,
                                    hover: { size: 7 }
                                },
                                xaxis: {
                                    categories: labels,
                                    title: { text: 'Waktu', style: { fontSize: '12px', color: textColor } },
                                    labels: {
                                        style: { colors: textColor, fontSize: '11px' },
                                        rotate: labels.length > 50 ? -45 : 0,
                                        rotateAlways: false
                                    },
                                    axisBorder: { color: gridColor },
                                    axisTicks: { color: gridColor }
                                },
                                yaxis: {
                                    title: { text: 'pH', style: { fontSize: '12px', color: textColor } },
                                    min: 0,
                                    max: 8,
                                    tickAmount: 8,
                                    labels: {
                                        style: { colors: textColor, fontSize: '11px' },
                                        formatter: function(value) { return value.toFixed(1); }
                                    }
                                },
                                grid: {
                                    borderColor: gridColor,
                                    strokeDashArray: 3,
                                    xaxis: { lines: { show: true } },
                                    yaxis: { lines: { show: true } }
                                },
                                legend: {
                                    position: 'top',
                                    horizontalAlign: 'left',
                                    fontSize: '12px',
                                    labels: { colors: textColor },
                                    markers: { width: 10, height: 10, radius: 12 },
                                    itemMargin: { horizontal: 15, vertical: 5 }
                                },
                                tooltip: {
                                    shared: true,
                                    intersect: false,
                                    theme: isDark ? 'dark' : 'light',
                                    y: { formatter: function(value) { return value !== undefined ? value.toFixed(2) : 'N/A'; } }
                                },
                                theme: { mode: isDark ? 'dark' : 'light' },
                                noData: {
                                    text: 'No data available',
                                    align: 'center',
                                    verticalAlign: 'middle',
                                    style: { color: textColor, fontSize: '14px' }
                                }
                            };
                            
                            this.chart = new ApexCharts(chartElement, options);
                            this.chart.render();
                        }
                    }"
                    x-init="$nextTick(() => initChart())"
                    @chart-data-updated.window="chartData = $event.detail.chartData; renderChart(chartData)"
                >
                    <div x-ref="chartContainer" style="height: 350px; min-height: 350px;"></div>
                </div>
            </div>

            <!-- Right Side: Statistics -->
            <div class="lg:col-span-1 space-y-3">
                <!-- Top Row: Amount dossing & Dossing count -->
                <div class="grid grid-cols-2 gap-3">
                    <!-- Amount dossing (gr) -->
                    <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6 text-center">
                        <p class="text-sm text-neutral-600 dark:text-neutral-400 mb-2">{{ __("Amount dossing (gr)") }}</p>
                        <h2 class="text-4xl font-bold text-neutral-900 dark:text-neutral-100">{{ $statistics['total_dossing'] }}</h2>
                    </div>

                    <!-- Dossing count -->
                    <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6 text-center">
                        <p class="text-sm text-neutral-600 dark:text-neutral-400 mb-2">{{ __("Dossing count") }}</p>
                        <h2 class="text-4xl font-bold text-neutral-900 dark:text-neutral-100">{{ $statistics['dossing_count'] }}</h2>
                    </div>
                </div>

                <!-- Bottom Row: Highest PH & Lowest PH -->
                <div class="grid grid-cols-2 gap-3">
                    <!-- Highest PH -->
                    <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6 text-center">
                        <p class="text-sm text-neutral-600 dark:text-neutral-400 mb-2">{{ __("Highest PH") }}</p>
                        <h2 class="text-4xl font-bold text-neutral-900 dark:text-neutral-100">{{ $statistics['highest_ph'] }}</h2>
                    </div>

                    <!-- Lowest PH -->
                    <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6 text-center">
                        <p class="text-sm text-neutral-600 dark:text-neutral-400 mb-2">{{ __("Lowest PH") }}</p>
                        <h2 class="text-4xl font-bold text-neutral-900 dark:text-neutral-100">{{ $statistics['lowest_ph'] }}</h2>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Theme Change Listener -->
    <script>
        // Update chart on theme change
        window.addEventListener('caldera-theme-changed', function() {
            // Dispatch event to re-render chart with new theme
            window.dispatchEvent(new CustomEvent('chart-data-updated', {
                detail: { chartData: @json($chartData) }
            }));
        });
    </script>
</div>