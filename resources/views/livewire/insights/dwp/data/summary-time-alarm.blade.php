<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use App\Models\InsDwpTimeAlarmCount;
use App\Models\InsDwpDevice;
use Carbon\Carbon;
use App\Traits\HasDateRangeFilter;

new #[Layout("layouts.app")] class extends Component {
    use HasDateRangeFilter;

    #[Url]
    public string $start_at = "";

    #[Url]
    public string $end_at = "";

    #[Url]
    public $device_id;

    #[Url]
    public string $line = "";

    #[Url]
    public string $timeView = "daily"; // daily or weekly

    public $view = "summary-time-alarm";

    public array $devices = [];
    public array $summaryStats = [];
    public array $lineChartData = [];
    public array $dailyChartData = [];
    public array $count = [];
    public array $cumulativeData = [];
    public $peakHourOrDay = null;
    public $peakCount = 0;
    public $averagePerPeriod = 0;
    public $longestQueueTime = 0;
    public $topLine = null;
    public $topLineCount = 0;

    public function mount()
    {
        if (! $this->start_at || ! $this->end_at) {
            $this->setThisWeek();
        }

        $this->devices = InsDwpDevice::orderBy("name")
            ->get()
            ->pluck("name", "id")
            ->toArray();

        $this->cumulativeData = $this->getDataSummaryLine();
        
        // update menu
        $this->dispatch("update-menu", $this->view);
        
        // Initial data load
        $this->update();
    }

    private function getCountsQuery()
    {
        $start = Carbon::parse($this->start_at);
        $end   = Carbon::parse($this->end_at)->endOfDay();

        $query = InsDwpTimeAlarmCount::whereBetween("created_at", [$start, $end]);

        if ($this->device_id) {
            $device = InsDwpDevice::find($this->device_id);
            if ($device) {
                $deviceLines = $device->getLines();
                $query->whereIn("line", $deviceLines);
            }
        }

        if ($this->line) {
            $query->where("line", "like", "%" . strtoupper(trim($this->line)) . "%");
        }

        return $query;
    }

    private function calculateSummaryStats()
    {
        $counts = $this->getCountsQuery()->get();
        $lines = $counts->pluck('line')->unique();
        
        $this->summaryStats = [
            "total_lines" => $lines->count(),
            "total_records" => $counts->count(),
            "total_incremental" => $counts->sum('incremental'),
            "avg_incremental_per_line" => $lines->count() > 0 ? round($counts->sum('incremental') / $lines->count(), 2) : 0,
        ];
        
        // Calculate peak hour/day
        $this->calculatePeakTime();
        
        // Calculate average per period
        $this->calculateAveragePerPeriod();
        
        // Get longest queue time
        $this->getLongestQueueTime();
        
        // Get top line
        $this->getTopLine();
    }
    
    private function calculatePeakTime()
    {
        $counts = $this->getCountsQuery()->get();
        
        if ($this->timeView === 'daily') {
            // Group by hour
            $hourlyData = $counts->groupBy(function($item) {
                return Carbon::parse($item->created_at)->format('H');
            })->map(function($items) {
                return $items->sum('incremental');
            })->sortDesc();
            
            if ($hourlyData->isNotEmpty()) {
                $this->peakHourOrDay = $hourlyData->keys()->first() . ':00';
                $this->peakCount = $hourlyData->first();
            }
        } else {
            // Group by date
            $dailyData = $counts->groupBy(function($item) {
                return Carbon::parse($item->created_at)->format('Y-m-d');
            })->map(function($items) {
                return $items->sum('incremental');
            })->sortDesc();
            
            if ($dailyData->isNotEmpty()) {
                $this->peakHourOrDay = Carbon::parse($dailyData->keys()->first())->format('d M Y');
                $this->peakCount = $dailyData->first();
            }
        }
    }
    
    private function calculateAveragePerPeriod()
    {
        $counts = $this->getCountsQuery()->get();
        
        if ($this->timeView === 'daily') {
            // Average per hour (6:00 - 17:00 = 12 hours)
            $hourlyData = $counts->groupBy(function($item) {
                return Carbon::parse($item->created_at)->format('H');
            })->map(function($items) {
                return $items->sum('incremental');
            });
            
            $totalHours = 12; // 6:00 to 17:00
            $this->averagePerPeriod = $hourlyData->count() > 0 ? round($counts->sum('incremental') / $totalHours, 2) : 0;
        } else {
            // Average per day
            $start = Carbon::parse($this->start_at);
            $end = Carbon::parse($this->end_at);
            $totalDays = $start->diffInDays($end) + 1;
            
            $this->averagePerPeriod = $totalDays > 0 ? round($counts->sum('incremental') / $totalDays, 2) : 0;
        }
    }
    
    private function getLongestQueueTime()
    {
        $longDurationData = InsDwpTimeAlarmCount::orderBy('duration', 'desc')
            ->whereBetween('created_at', [
                Carbon::parse($this->start_at)->startOfDay(),
                Carbon::parse($this->end_at)->endOfDay()
            ]);
            
        if ($this->line) {
            $longDurationData->where("line", "like", "%" . strtoupper(trim($this->line)) . "%");
        }
        
        $result = $longDurationData->first();
        $this->longestQueueTime = $result ? $result->duration : 0;
    }
    
    private function getTopLine()
    {
        $counts = $this->getCountsQuery()->get();
        
        $lineSummary = $counts->groupBy('line')->map(function ($lineCounts) {
            return $lineCounts->sum('incremental');
        })->sortDesc();
        
        if ($lineSummary->isNotEmpty()) {
            $this->topLine = $lineSummary->keys()->first();
            $this->topLineCount = $lineSummary->first();
        }
    }

    private function generateLineChartData()
    {
        $counts = $this->getCountsQuery()->get();
        $lineSummary = $counts->groupBy('line')->map(function ($lineCounts) {
            return $lineCounts->sum('incremental');
        });

        $this->lineChartData = [
            'labels' => $lineSummary->keys()->toArray(),
            'datasets' => [
                [
                    'label' => 'Total Incremental',
                    'data' => $lineSummary->values()->toArray(),
                    'backgroundColor' => 'rgba(59, 130, 246, 0.6)',
                    'borderColor' => 'rgba(59, 130, 246, 1)',
                    'borderWidth' => 2,
                ]
            ]
        ];
    }

    private function generateDailyChartData()
    {
        $start = Carbon::parse($this->start_at);
        $end = Carbon::parse($this->end_at);

        // Get filtered lines based on current filters
        $lines = $this->getCountsQuery()->distinct('line')->pluck('line');
        $dates = [];
        $datasets = [];

        if ($this->timeView === 'weekly') {
            // Weekly view - group by date
            $current = $start->copy();
            while ($current <= $end) {
                $dates[] = $current->format('Y-m-d');
                $current->addDay();
            }

            // Color palette for different lines
            $colors = [
                'rgba(59, 130, 246, 0.6)',   // Blue
                'rgba(16, 185, 129, 0.6)',   // Green  
                'rgba(245, 101, 101, 0.6)',  // Red
                'rgba(251, 191, 36, 0.6)',   // Yellow
                'rgba(139, 92, 246, 0.6)',   // Purple
                'rgba(236, 72, 153, 0.6)',   // Pink
            ];

            foreach ($lines as $index => $line) {
                $lineData = [];
                
                foreach ($dates as $date) {
                    $dailyCount = $this->getCountsQuery()
                        ->where('line', $line)
                        ->whereDate('created_at', $date)
                        ->sum('incremental');
                    
                    $lineData[] = $dailyCount;
                }

                $color = $colors[$index % count($colors)];
                $borderColor = str_replace('0.6', '1', $color);

                $datasets[] = [
                    'label' => $line,
                    'data' => $lineData,
                    'backgroundColor' => $color,
                    'borderColor' => $borderColor,
                    'borderWidth' => 2,
                    'tension' => 0.4,
                ];
            }
        } else {
            // Daily view - group by hour (6:00 to 17:00)
            for ($hour = 6; $hour <= 17; $hour++) {
                $dates[] = sprintf('%02d:00', $hour);
            }

            // Color palette for different lines
            $colors = [
                'rgba(59, 130, 246, 0.6)',   // Blue
                'rgba(16, 185, 129, 0.6)',   // Green  
                'rgba(245, 101, 101, 0.6)',  // Red
                'rgba(251, 191, 36, 0.6)',   // Yellow
                'rgba(139, 92, 246, 0.6)',   // Purple
                'rgba(236, 72, 153, 0.6)',   // Pink
            ];

            foreach ($lines as $index => $line) {
                $lineData = [];
                
                foreach (range(6, 17) as $hour) {
                    $hourlyCount = $this->getCountsQuery()
                        ->where('line', $line)
                        ->whereRaw('HOUR(created_at) = ?', [$hour])
                        ->sum('incremental');
                    
                    $lineData[] = $hourlyCount;
                }

                $color = $colors[$index % count($colors)];
                $borderColor = str_replace('0.6', '1', $color);

                $datasets[] = [
                    'label' => $line,
                    'data' => $lineData,
                    'backgroundColor' => $color,
                    'borderColor' => $borderColor,
                    'borderWidth' => 2,
                    'tension' => 0.4,
                ];
            }
        }

        $this->dailyChartData = [
            'labels' => $dates,
            'datasets' => $datasets
        ];
    }

    #[On("updated")]
    public function update()
    {
        $this->calculateSummaryStats();
        $this->generateLineChartData();
        $this->generateDailyChartData();
        $this->generateCharts();
    }

    private function generateCharts()
    {
        $this->dispatch('refresh-charts', [
                    'lineChartData' => $this->lineChartData,
                    'dailyChartData' => $this->dailyChartData,
                ]);
    }

    /**
     * GET DATA LINE
     * Description : This code for get data line on database ins_dwp_device
     */            
    private function getDataLine($line=null)
    {
        $lines = [];
        $dataRaws = InsDwpDevice::orderBy("name")
            ->select("name", "id", "config")
            ->get()->toArray();
        foreach($dataRaws as $dataRaw){
            if (!empty($line)){
                if ($dataRaw['config'][0]['line'] == strtoupper($line)){
                    $lines[] = $dataRaw['config'][0];
                    break;
                }
            }else {
                $lines[] = $dataRaw['config'][0];
            }
        }
        return $lines;
    }

     /**
     * GET DATA SUMMARY ALL LINE
     * Description : This code for get data line on database ins_dwp_device
     */ 
    private function getDataSummaryLine($line=null)
    {
        $lines = [];
        $dataRaws = InsDwpDevice::orderBy("name")
            ->select("name", "id", "config", "created_at")
            ->get()->toArray();
        foreach($dataRaws as $dataRaw){
            if (!empty($line)){
                if ($dataRaw['config'][0]['line'] == strtoupper($line)){
                    $lines[] = [
                        "id" => $dataRaw['id'],
                        "line" => $dataRaw['name'],
                        "cumulative" => 100,
                        "created_at" => $dataRaw["created_at"],
                    ];
                    break;
                }
            }else {
                $lines[] = [
                    "id" => $dataRaw['id'],
                    "line" => $dataRaw['name'],
                    "cumulative" => 100,
                    "created_at" => $dataRaw["created_at"],
                ];
            }
        }
        return $lines;
    }

    public function updated()
    {
        $this->update();
    }
    
    public function updatedTimeView()
    {
        $this->update();
    }
    
    public function updatedLine()
    {
        $this->update();
    }
    
    public function updatedStartAt()
    {
        $this->update();
    }
    
    public function updatedEndAt()
    {
        $this->update();
    }
};

?>

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
            <div class="grid grid-cols-2 lg:flex gap-3">
                <div class="mt-6">
                    <x-slot name="trigger">
                                <x-text-button class="uppercase ml-3">
                                    {{ __("Rentang") }}
                                    <i class="icon-chevron-down ms-1"></i>
                                </x-text-button>
                    </x-slot>
                    <x-select wire:model.live="line" class="w-full lg:w-32">
                        @foreach($this->getDataLine() as $lineData)
                            <option value="{{$lineData['line']}}">{{$lineData['line']}}</option>
                        @endforeach
                    </x-select>
                </div>
            </div>
            <div class="border-l border-neutral-300 dark:border-neutral-700 mx-2"></div>
            <div class="grid grid-cols-2 lg:flex gap-3">
                <div class="mt-6">
                    <x-select wire:model.live="timeView" class="w-full lg:w-32">
                        <option value="daily">{{ __("Daily") }}</option>
                        <option value="weekly">{{ __("Weekly") }}</option>
                    </x-select>
                </div>
            </div>
            <div class="border-l border-neutral-300 dark:border-neutral-700 mx-2"></div>
            <div class="grow flex justify-center gap-x-2 items-center">
                <div wire:loading.class.remove="hidden" class="flex gap-3 hidden">
                    <div class="relative w-3">
                        <x-spinner class="sm mono"></x-spinner>
                    </div>
                    <div>
                        {{ __("Memuat...") }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- Peak Hour/Day Card -->
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
            <div class="flex flex-col">
                <p class="text-sm text-neutral-600 dark:text-neutral-400 mb-2">
                    @if($timeView === 'daily')
                        {{ __("Peak Hour") }}
                    @else
                        {{ __("Peak Day") }}
                    @endif
                </p>
                @if($timeView === 'daily')
                    <p class="text-4xl font-bold text-blue-600 dark:text-blue-400">
                        {{ $peakHourOrDay ?? 'N/A' }}
                    </p>
                @else
                    <p class="text-3xl font-bold text-blue-600 dark:text-blue-400 leading-tight">
                        {{ $peakHourOrDay ?? 'N/A' }}
                    </p>
                @endif
                <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-2">
                    {{ number_format($peakCount) }} {{ __("alarms") }}
                </p>
            </div>
        </div>

        <!-- Average Per Period Card -->
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
            <div class="flex flex-col">
                <p class="text-sm text-neutral-600 dark:text-neutral-400 mb-2">
                    @if($timeView === 'daily')
                        {{ __("Avg Per Hour") }}
                    @else
                        {{ __("Avg Per Day") }}
                    @endif
                </p>
                <p class="text-4xl font-bold text-green-600 dark:text-green-400">
                    {{ number_format($averagePerPeriod, 2) }}
                </p>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-2">
                    {{ __("average alarms") }}
                </p>
            </div>
        </div>

        <!-- Long Queue Time Card -->
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
            <div class="flex flex-col">
                <p class="text-sm text-neutral-600 dark:text-neutral-400 mb-2">
                    {{ __("Long Queue Time") }}
                </p>
                <p class="text-4xl font-bold text-orange-600 dark:text-orange-400">
                    {{ $longestQueueTime }} <span class="text-xl">sec</span>
                </p>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-2">
                    {{ __("maximum duration") }}
                </p>
            </div>
        </div>

        <!-- Top Line Card -->
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
            <div class="flex flex-col">
                <p class="text-sm text-neutral-600 dark:text-neutral-400 mb-2">
                    {{ __("Top Line") }}
                </p>
                <p class="text-4xl font-bold text-purple-600 dark:text-purple-400">
                    {{ $topLine ?? 'N/A' }}
                </p>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 mt-2">
                    {{ number_format($topLineCount) }} {{ __("alarms") }}
                </p>
            </div>
        </div>
    </div>

    <!-- Charts Grid -->
    <div class="grid grid-cols- lg:grid-cols-1 gap-6">
        <!-- Daily Trend Chart -->
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
            <h3 class="text-lg font-medium text-neutral-900 dark:text-neutral-100 mb-4">
                @if($timeView === 'daily')
                    {{ __("Hourly Trends") }}
                @else
                    {{ __("Daily Trends") }}
                @endif
            </h3>
            <div class="h-96">
                <canvas id="dailyChart" wire:ignore></canvas>
            </div>
        </div>
    </div>
</div>

@script
    <script>
        let lineChart, dailyChart;

        function initCharts(lineData, dailyData) {
            // Line Chart (Bar Chart)
            const lineCtx = document.getElementById('lineChart');
            if (lineChart) lineChart.destroy();
            
            lineChart = new Chart(lineCtx, {
                type: 'bar',
                data: lineData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
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

            // Daily Chart (Line Chart)
            const dailyCtx = document.getElementById('dailyChart');
            if (dailyChart) dailyChart.destroy();
            
            dailyChart = new Chart(dailyCtx, {
                type: 'line',
                data: dailyData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
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

        // Listen for refresh event
        $wire.on('refresh-charts', function(event) {
            const data = event[0] || event;
            initCharts(data.lineChartData, data.dailyChartData);
        });

        // Initial load
        $wire.$dispatch('updated');
    </script>
@endscript
</div>