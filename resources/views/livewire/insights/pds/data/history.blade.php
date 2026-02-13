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
use Illuminate\Pagination\LengthAwarePaginator;

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

    public $stdMinPh = 2;
    public $stdMaxPh = 3;

    public function getStdMinPh()
    {
        try {
            $device = InsPhDosingDevice::where("id", $this->plant)->first();
            if (!$device || !$device->config) {
                return 2;
            }
            $dataJson = $device->config;
            return $dataJson['standard_ph']['min'] ?? 2;
        } catch (\Exception $e) {
            return 2;
        }
    }

    public function getStdMaxPh()
    {
        try {
            $device = InsPhDosingDevice::where("id", $this->plant)->first();
            if (!$device || !$device->config) {
                return 3;
            }
            $dataJson = $device->config;
            return $dataJson['standard_ph']['max'] ?? 3;
        } catch (\Exception $e) {
            return 3;
        }
    }

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
        $this->stdMinPh = $this->getStdMinPh();
        $this->stdMaxPh = $this->getStdMaxPh();
        $this->dispatch("update-menu", $this->view);
    }

    public function updatedStartAt()
    {
        $this->resetPage();
    }
    
    public function updatedEndAt()
    {
        $this->resetPage();
    }
    
    public function updatedPlant()
    {
        $this->stdMinPh = $this->getStdMinPh();
        $this->stdMaxPh = $this->getStdMaxPh();
        $this->resetPage();
    }

    #[On('update')]
    public function refreshChart(): void
    {
        $this->dispatch('chart-data-updated', chartData: $this->getChartData());
    }
    
    public function getStatistics()
    {
        try {
            $dataLog = InsPhDosingLog::with('device')
                ->where("dosing_amount", ">", 0)
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

            // Return empty statistics if no data
            if ($dataPh->isEmpty() && $dataLog->isEmpty()) {
                return $this->getEmptyStatistics();
            }

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
                    $phValue = $count->ph_value['current_ph'] ?? $count->ph_value['current'] ?? null;
                } else {
                    $phValue = null;
                }
                
                // Skip invalid pH values
                if ($phValue === null || !is_numeric($phValue)) {
                    continue;
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
                
                // Safely get dosing amount
                $dosingAmount = is_numeric($log->dosing_amount) ? (float) $log->dosing_amount : 0;
                $fiveMinuteStats[$intervalKey]['dosing_amounts'][] = $dosingAmount;
                $fiveMinuteStats[$intervalKey]['dosing_count']++;
            }
            
            // Return empty statistics if no valid data after processing
            if (empty($fiveMinuteStats)) {
                return $this->getEmptyStatistics();
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
        } catch (\Exception $e) {
            return $this->getEmptyStatistics();
        }
    }
    
    private function getEmptyStatistics(): array
    {
        return [
            'total_dossing' => 0,
            'dossing_count' => 0,
            'highest_ph' => 0,
            'lowest_ph' => 0,
            'intervals' => [],
        ];
    }

    public function getUniquePlants()
    {
        try {
            return InsPhDosingDevice::orderBy("plant")
                ->get()
                ->pluck("plant", "id")
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getCountsQuery()
    {
        $query = InsPhDosingCount::with('device')
            ->whereBetween("created_at", [Carbon::parse($this->start_at)->startOfDay(), Carbon::parse($this->end_at)->endOfDay()]);

        if ($this->plant) {
            $query->whereHas('device', function($q) {
                $q->where('id', $this->plant);
            });
        }

        return $query->orderBy("created_at", "DESC");
    }

    public function getChartData()
    {
        try {
            $data = InsPhDosingCount::with('device')
                ->whereBetween("created_at", [Carbon::parse($this->start_at)->startOfDay(), Carbon::parse($this->end_at)->endOfDay()]);

            if ($this->plant) {
                $data->whereHas('device', function($q) {
                    $q->where('id', $this->plant);
                });
            }

            $data = $data->orderBy("created_at", "ASC")->get();

            // Fetch dosing log data
            $dosingLogs = InsPhDosingLog::with('device')
                ->where("dosing_amount", ">", 0)
                ->whereBetween("created_at", [Carbon::parse($this->start_at)->startOfDay(), Carbon::parse($this->end_at)->endOfDay()]);

            if ($this->plant) {
                $dosingLogs->whereHas('device', function($q) {
                    $q->where('id', $this->plant);
                });
            }

            $dosingLogs = $dosingLogs->orderBy("created_at", "ASC")->get();

            // Return empty chart data if no data exists
            if ($data->isEmpty()) {
                return $this->getEmptyChartData();
            }

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
                    $phValue = $count->ph_value['current_ph'] ?? $count->ph_value['current'] ?? null;
                } else {
                    $phValue = null;
                }
                
                // Skip invalid pH values
                if ($phValue === null || !is_numeric($phValue)) {
                    continue;
                }
                
                // Initialize 5-minute interval group if not exists
                if (!isset($fiveMinuteData[$intervalKey])) {
                    $fiveMinuteData[$intervalKey] = [
                        'sum' => 0,
                        'count' => 0,
                        'timestamp' => $roundedTimestamp,
                        'has_dosing' => false
                    ];
                }
                
                // Accumulate pH values
                $fiveMinuteData[$intervalKey]['sum'] += (float) $phValue;
                $fiveMinuteData[$intervalKey]['count']++;
            }

            // Process dosing events and mark intervals
            foreach ($dosingLogs as $log) {
                $timestamp = Carbon::parse($log->created_at);
                
                // Round to nearest 5-minute interval
                $minute = $timestamp->minute;
                $roundedMinute = floor($minute / 5) * 5;
                $roundedTimestamp = $timestamp->copy()->second(0)->minute($roundedMinute);
                
                // Create 5-minute interval key
                $intervalKey = $roundedTimestamp->format('Y-m-d H:i:00');
                
                // Initialize interval if not exists (dosing event without pH reading in this window)
                if (!isset($fiveMinuteData[$intervalKey])) {
                    $fiveMinuteData[$intervalKey] = [
                        'sum' => 0,
                        'count' => 0,
                        'timestamp' => $roundedTimestamp,
                        'has_dosing' => false
                    ];
                }
                
                // Mark this interval as having dosing event
                $fiveMinuteData[$intervalKey]['has_dosing'] = true;
            }

            // Return empty chart if no valid data after processing
            if (empty($fiveMinuteData)) {
                return $this->getEmptyChartData();
            }

            // Calculate averages and prepare chart data
            $chartData = [
                'labels' => [],
                'phValues' => [],
                'dosingMarkers' => [],
            ];

            // Determine if we need to show dates in labels (multi-day range)
            $startDate = Carbon::parse($this->start_at);
            $endDate = Carbon::parse($this->end_at);
            $showDate = $startDate->diffInDays($endDate) > 0;

            // Sort by interval key and build final arrays
            ksort($fiveMinuteData);
            
            $lastKnownPh = null;
            
            foreach ($fiveMinuteData as $intervalKey => $intervalInfo) {
                $hasPh = $intervalInfo['count'] > 0;
                
                // Skip intervals with no pH data to avoid NaN in SVG path rendering
                // (dosing-only intervals produce null in the line series which breaks smooth curves)
                if (!$hasPh) {
                    continue;
                }
                
                $avgPh = $intervalInfo['sum'] / $intervalInfo['count'];
                $lastKnownPh = round($avgPh, 2);
                
                // Format label based on date range
                if ($showDate) {
                    $chartData['labels'][] = $intervalInfo['timestamp']->format('d/m H:i');
                } else {
                    $chartData['labels'][] = $intervalInfo['timestamp']->format('H:i');
                }
                
                $chartData['phValues'][] = round($avgPh, 2);
                
                // Add dosing marker if this interval has dosing event
                if ($intervalInfo['has_dosing']) {
                    $chartData['dosingMarkers'][] = round($avgPh, 2);
                } else {
                    $chartData['dosingMarkers'][] = null;
                }
            }

            return $chartData;
        } catch (\Exception $e) {
            return $this->getEmptyChartData();
        }
    }
    
    private function getEmptyChartData(): array
    {
        return [
            'labels' => [],
            'phValues' => [],
            'dosingMarkers' => [],
        ];
    }

    public function with(): array
    {
        try {
            return [
                'counts' => $this->getCountsQuery()->paginate($this->perPage),
                'chartData' => $this->getChartData(),
                'statistics' => $this->getStatistics(),
            ];
        } catch (\Exception $e) {
            return [
                'counts' => new LengthAwarePaginator([], 0, $this->perPage),
                'chartData' => $this->getEmptyChartData(),
                'statistics' => $this->getEmptyStatistics(),
            ];
        }
    }

}; ?>

<div wire:poll.20s>
    <div class="p-0 sm:p-1 mb-6">
        <div class="relative flex flex-col lg:flex-row gap-3 w-full bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-4">
            <!-- Loading Overlay -->
            <div wire:loading wire:target="start_at,end_at,plant,setToday,setYesterday,setThisWeek,setLastWeek,setThisMonth,setLastMonth" class="absolute inset-0 bg-white/70 dark:bg-neutral-800/70 backdrop-blur-sm rounded-lg z-10 flex items-center justify-center">
                <div class="flex flex-col items-center gap-3">
                    <svg class="animate-spin h-8 w-8 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span class="text-sm text-neutral-600 dark:text-neutral-400 font-medium">{{ __("Loading...") }}</span>
                </div>
            </div>

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
                    <div class="relative">
                        <x-text-input wire:model.live="start_at" type="date" class="w-40" />
                        <div wire:loading wire:target="start_at" class="absolute right-2 top-2">
                            <svg class="animate-spin h-5 w-5 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="relative">
                        <x-text-input wire:model.live="end_at" type="date" class="w-40" />
                        <div wire:loading wire:target="end_at" class="absolute right-2 top-2">
                            <svg class="animate-spin h-5 w-5 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </div>
                    </div>
                </div>
            </div>
            <div class="border-l border-neutral-300 dark:border-neutral-700 mx-2"></div>
            <div class="grid grid-cols-3 gap-3">
                <div class="relative">
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Plant") }}</label>
                    <x-select wire:model.live="plant" class="w-full">
                        <option value="">{{ __("Semua") }}</option>
                        @foreach($this->getUniquePlants() as $id => $plantOption)
                            <option value="{{$id}}">{{$plantOption}}</option>
                        @endforeach
                    </x-select>
                    <div wire:loading wire:target="plant" class="absolute right-3 top-9">
                        <svg class="animate-spin h-5 w-5 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                </div>
            </div>
            <div class="border-l border-neutral-300 dark:border-neutral-700 mx-2"></div>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-3 mt-2" wire:key="statistics-{{ $start_at }}-{{ $end_at }}-{{ $plant }}">
            <!-- Right Side: Statistics -->
            <!-- Top Row: Amount dossing & Dossing count -->
                <!-- Amount dossing (gr) -->
                <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6 text-center">
                    <p class="text-lg font-semibold text-neutral-600 dark:text-neutral-400 mb-2">{{ __("Amount dossing (gr)") }}</p>
                    <h2 class="text-4xl font-bold text-neutral-900 dark:text-neutral-100">{{ $statistics['total_dossing'] }}</h2>
                </div>

                <!-- Dossing count -->
                <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6 text-center">
                    <p class="text-lg font-semibold text-neutral-600 dark:text-neutral-400 mb-2">{{ __("Dossing count") }}</p>
                    <h2 class="text-4xl font-bold text-neutral-900 dark:text-neutral-100">{{ $statistics['dossing_count'] }}</h2>
                </div>

            <!-- Bottom Row: Highest PH & Lowest PH -->
                <!-- Highest PH -->
                <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6 text-center">
                    <p class="text-lg font-semibold text-neutral-600 dark:text-neutral-400 mb-2">{{ __("Highest PH") }}</p>
                    <h2 class="text-4xl font-bold text-neutral-900 dark:text-neutral-100">{{ $statistics['highest_ph'] }}</h2>
                </div>

                <!-- Lowest PH -->
                <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6 text-center">
                    <p class="text-lg font-semibold text-neutral-600 dark:text-neutral-400 mb-2">{{ __("Lowest PH") }}</p>
                    <h2 class="text-4xl font-bold text-neutral-900 dark:text-neutral-100">{{ $statistics['lowest_ph'] }}</h2>
                </div>
        </div>
        <div class="mt-2">
            <!-- Left Side: Chart Area -->
            <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
                
                <h3 class="text-lg text-center font-semibold text-neutral-700 dark:text-neutral-300 mb-4">{{ __("Daily Trend Chart pH") ." (5 Minutes Interval)" }}</h3>
                
                @if(empty($chartData['labels']) || count($chartData['labels']) === 0)
                    <div class="flex items-center justify-center" style="height: 350px;">
                        <p class="text-neutral-500 dark:text-neutral-400 text-lg">{{ __("No data available for selected filters") }}</p>
                    </div>
                @else
                <div 
                    wire:key="chart-container-{{ md5(json_encode($chartData)) }}"
                    x-data="{ 
                        chart: null,
                        init() {
                            this.$nextTick(() => this.initChart());
                        },
                        destroy() {
                            if (this.chart) {
                                try { this.chart.destroy(); } catch(e) {}
                                this.chart = null;
                            }
                        },
                        initChart() {
                            if (typeof ApexCharts === 'undefined') {
                                setTimeout(() => this.initChart(), 100);
                                return;
                            }
                            
                            const chartElement = this.$refs.chartContainer;
                            if (!chartElement) {
                                return;
                            }
                            
                            // Destroy existing chart
                            if (this.chart) {
                                try {
                                    this.chart.destroy();
                                } catch(e) {}
                                this.chart = null;
                            }
                            
                            const chartData = @js($chartData);
                            const stdMinPh = {{ $stdMinPh }};
                            const stdMaxPh = {{ $stdMaxPh }};
                            
                            const phSeries = chartData.phValues || [];
                            const labels = chartData.labels || [];
                            const dosingMarkers = chartData.dosingMarkers || [];
                            
                            if (labels.length === 0) {
                                return;
                            }
                            
                            const maxLimit = Array(labels.length).fill(stdMaxPh);
                            const minLimit = Array(labels.length).fill(stdMinPh);
                            
                            const isDark = document.documentElement.classList.contains('dark');
                            const textColor = isDark ? '#d4d4d4' : '#525252';
                            const gridColor = isDark ? '#404040' : '#e5e7eb';
                            
                            const options = {
                                series: [
                                    { name: 'Nilai pH', data: phSeries, type: 'line' },
                                    { name: 'Batas Maksimal (pH ' + stdMaxPh + ')', data: maxLimit, type: 'line' },
                                    { name: 'Batas Minimal (pH ' + stdMinPh + ')', data: minLimit, type: 'line' },
                                    { name: 'Dosing Event', data: dosingMarkers, type: 'scatter' }
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
                                colors: ['#3b82f6', '#ef4444', '#ef4444', '#10b981'],
                                stroke: { width: [3, 2, 2, 0], curve: 'smooth', dashArray: [0, 5, 5, 0] },
                                markers: {
                                    size: [5, 0, 0, 0],
                                    colors: ['#3b82f6', '#ef4444', '#ef4444', '#10b981'],
                                    strokeColors: '#fff',
                                    strokeWidth: 2,
                                    hover: { size: [7, 0, 0, 0] }
                                },
                                dataLabels: {
                                    enabled: true,
                                    enabledOnSeries: [3],
                                    formatter: function(value) {
                                        return value !== null && value !== undefined ? '▼' : '';
                                    },
                                    style: {
                                        fontSize: '16px',
                                        fontWeight: 'bold',
                                        colors: ['#10b981']
                                    },
                                    background: { enabled: false },
                                    offsetY: -5
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
                                        formatter: function(value) { 
                                            if (value === null || value === undefined || isNaN(value)) {
                                                return '0.0';
                                            }
                                            return Number(value).toFixed(1); 
                                        }
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
                                    y: { 
                                        formatter: function(value) { 
                                            if (value === null || value === undefined || isNaN(value)) {
                                                return 'N/A';
                                            }
                                            return Number(value).toFixed(2); 
                                        } 
                                    }
                                },
                                theme: { mode: isDark ? 'dark' : 'light' }
                            };
                            
                            try {
                                this.chart = new ApexCharts(chartElement, options);
                                this.chart.render();
                            } catch(error) {
                                console.error('Chart render error:', error);
                            }
                        }
                    }"
                    @caldera-theme-changed.window="initChart()"
                >
                    <div x-ref="chartContainer" wire:ignore style="height: 350px; min-height: 350px;"></div>
                </div>
                @endif
            </div>
        </div>
    </div>

</div>