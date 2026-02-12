<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Traits\HasDateRangeFilter;
use App\Models\InsDwpDevice;
use Livewire\WithPagination;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use App\Models\InsDwpCount;
use Carbon\Carbon;

new #[Layout("layouts.app")] class extends Component {
    use WithPagination;
    use HasDateRangeFilter;

    #[Url]
    public string $start_at = "";

    #[Url]
    public string $end_at = "";

    #[Url]
    public $device_id;

    #[Url]
    public string $line = "";

    // Selected machine filter (bound to the view via wire:model)
    #[Url]
    public string $machine = "";

    // Selected position filter (L for Left, R for Right)
    #[Url]
    public string $position = "";

    public array $devices = [];
    public int $perPage = 20;
    public string $view = "pressure";

    // Cache for computed data
    private $cachedChartData = null;
    private $cacheKey = null;

    public function mount()
    {
        if (!$this->start_at || !$this->end_at) {
            $this->setThisWeek();
        }

        // Cache device list - this rarely changes
        $this->devices = cache()->remember('dwp_devices_list', 3600, function () {
            return InsDwpDevice::orderBy("name")
                ->pluck("name", "id")
                ->toArray();
        });

        $this->dispatch("update-menu", $this->view);
    }

    private function getCountsQuery()
    {
        $start = Carbon::parse($this->start_at);
        $end = Carbon::parse($this->end_at)->endOfDay();

        // Use select to only fetch needed columns for pagination
        $query = InsDwpCount::select(
            "ins_dwp_counts.id",
            "ins_dwp_counts.line",
            "ins_dwp_counts.mechine",
            "ins_dwp_counts.position",
            "ins_dwp_counts.duration",
            "ins_dwp_counts.pv",
            "ins_dwp_counts.created_at as count_created_at"
        )
            ->whereBetween("ins_dwp_counts.created_at", [$start, $end]);

        if ($this->device_id) {
            // Cache device lines lookup
            $cacheKey = "device_lines_{$this->device_id}";
            $deviceLines = cache()->remember($cacheKey, 1800, function () {
                $device = InsDwpDevice::find($this->device_id);
                return $device ? $device->getLines() : [];
            });
            
            if (!empty($deviceLines)) {
                $query->whereIn("ins_dwp_counts.line", $deviceLines);
            }
        }

        if ($this->line) {
            $query->where("ins_dwp_counts.line", "like", "%" . strtoupper(trim($this->line)) . "%");
        }

        if (!empty($this->machine)) {
            $query->where('ins_dwp_counts.mechine', $this->machine);
        }

        return $query->orderBy("ins_dwp_counts.created_at", "DESC");
    }

    /**
     * GET DATA MACHINES
     * Description : This code for get data machines on database ins_dwp_device
     */
    private function getDataMachines($selectedLine = null)
    {
        if (!$selectedLine) {
            return [];
        }

        // Query for the specific device that handles this line to avoid loading all of them.
        $device = InsDwpDevice::whereJsonContains('config', [['line' => strtoupper($selectedLine)]])
            ->select('config')
            ->first();

        if ($device) {
            foreach ($device->config as $lineConfig) {
                if (strtoupper($lineConfig['line']) === strtoupper($selectedLine)) {
                    return $lineConfig['list_mechine'] ?? [];
                }
            }
        }
        return [];
    }

    /**
     * Helper function to calculate median
     */
    private function getMedian(array $array)
    {
        if (empty($array)) return 0;
        // Filter out non-numeric values
        $numericArray = array_filter($array, 'is_numeric');
        if (empty($numericArray)) return 0;

        sort($numericArray);
        $count = count($numericArray);
        $middle = floor(($count - 1) / 2);
        $median = ($count % 2) ? $numericArray[$middle] : ($numericArray[$middle] + $numericArray[$middle + 1]) / 2;

        return round($median);
    }

    /**
     * Repeat waveform values based on timestamps and duration
     * Same logic as raw data filter for consistent calculations
     */
    private function repeatWaveform(array $valuesRaw, array $timestampsRaw, int $duration): array {
        $count = count($valuesRaw);
        if ($count === 0 || count($timestampsRaw) !== $count) {
            return [];
        }
        // Normalize timestamps to seconds from start
        $startTs = (int)($timestampsRaw[0] / 1000);
        $secValueMap = [];
        $maxSec = 0;
        for ($i = 0; $i < $count; $i++) {
            $sec = (int)($timestampsRaw[$i] / 1000) - $startTs;
            $secValueMap[$sec] = $valuesRaw[$i];
            if ($sec > $maxSec) $maxSec = $sec;
        }
        // Build result array with repeated/held values
        $result = [];
        $lastValue = 0;
        for ($sec = 0; $sec <= $maxSec; $sec++) {
            if (isset($secValueMap[$sec])) {
                $lastValue = $secValueMap[$sec];
            }
            $result[] = $lastValue;
        }
        // add 0 for lasting seconds up to duration
        for ($sec = $maxSec + 1; $sec < $duration; $sec++) {
            $result[] = 0;
        }

        // Ensure result has exactly $duration elements
        if (count($result) < $duration) {
            $result = array_pad($result, $duration, 0);
        } elseif (count($result) > $duration) {
            $result = array_slice($result, 0, $duration);
        }

        // Always set the last duration slot to zero so the chart ends at 0
        if ($duration > 0) {
            $result[$duration - 1] = 0;
        }

        return $result;
    }

    /**
     * Calculates the 5-point summary (min, q1, median, q3, max) for a boxplot.
     */
    private function getBoxplotSummary(array $data): ?array
    {
        if (empty($data)) {
            return null; // Return null if there is no data
        }

        sort($data);
        $count = count($data);
        $min = $data[0];
        $max = $data[$count - 1];

        // Median (Q2)
        $mid_index = (int)floor($count / 2);
        $median = ($count % 2 === 0)
            ? ($data[$mid_index - 1] + $data[$mid_index]) / 2
            : $data[$mid_index];

        // Q1 (Median of lower half)
        $lower_half = array_slice($data, 0, $mid_index);
        $q1 = 0;
        if (!empty($lower_half)) {
            $q1_count = count($lower_half);
            $q1_mid_index = (int)floor($q1_count / 2);
            $q1 = ($q1_count % 2 === 0)
                ? ($lower_half[$q1_mid_index - 1] + $lower_half[$q1_mid_index]) / 2
                : $lower_half[$q1_mid_index];
        } else {
            $q1 = $min;
        }

        // Q3 (Median of upper half)
        $upper_half = array_slice($data, ($count % 2 === 0) ? $mid_index : $mid_index + 1);
        $q3 = 0;
        if (!empty($upper_half)) {
            $q3_count = count($upper_half);
            $q3_mid_index = (int)floor($q3_count / 2);
            $q3 = ($q3_count % 2 === 0)
                ? ($upper_half[$q3_mid_index - 1] + $upper_half[$q3_mid_index]) / 2
                : $upper_half[$q3_mid_index];
        } else {
             $q3 = $max;
        }

        // Return the 5-point summary, rounded
        return array_map(fn($v) => round($v, 2), [$min, $q1, $median, $q3, $max]);
    }

    #[On("updated")]
    public function with(): array
    {
        $counts = $this->getCountsQuery()->paginate($this->perPage);
        
        // Only regenerate charts if filters changed
        $currentCacheKey = $this->getCacheKey();
        if ($this->cacheKey !== $currentCacheKey) {
            $this->cacheKey = $currentCacheKey;
            $this->generateCharts();
        }
        
        return [
            "counts" => $counts,
            "emergencyCounts" => $this->getEmergencyPressCount(),
        ];
    }

    #[On("updated")]
    public function update()
    {
        $this->cacheKey = null; // Force regeneration
        $this->generateCharts();
    }

    /**
     * Generate a cache key based on current filters
     */
    private function getCacheKey(): string
    {
        return md5(implode('_', [
            $this->start_at,
            $this->end_at,
            $this->line,
            $this->machine,
            $this->position,
            $this->device_id ?? ''
        ]));
    }

    // Generate Charts
    private function generateCharts()
    {
        // Build base query with only necessary columns for better performance
        $dataRaw = InsDwpCount::select(
            "ins_dwp_counts.pv",
            "ins_dwp_counts.position",
            "ins_dwp_counts.mechine",
            "ins_dwp_counts.duration",
            "ins_dwp_counts.line",
            "ins_dwp_counts.created_at as count_created_at"
        )
            ->whereBetween("ins_dwp_counts.created_at", [
                Carbon::parse($this->start_at),
                Carbon::parse($this->end_at)->endOfDay(),
            ]);

        if ($this->line) {
            $dataRaw->where("ins_dwp_counts.line", "like", "%" . strtoupper(trim($this->line)) . "%");
        }

        if (!empty($this->machine)) {
            $dataRaw->where('ins_dwp_counts.mechine', $this->machine);
        }

        if (!empty($this->position)) {
            $dataRaw->where('ins_dwp_counts.position', $this->position);
        }

        // Fetch data once and reuse for both charts
        $allData = $dataRaw->get();
        
        // Generate duration chart data
        $this->generateDurationChart($allData);
        
        // Generate pressure summary chart data
        $this->generatePressureSummaryChart($allData);

        // Filter for pressure data
        $presureData = $allData->whereNotNull('pv');
        $counts = $presureData;

        // Prepare arrays to hold median values for each of the 4 sensors
        $toeheel_left_data = [];
        $toeheel_right_data = [];
        $side_left_data = [];
        $side_right_data = [];

        // Loop through each database record - optimized processing
        foreach ($counts as $count) {
            // Decode JSON once
            $arrayPv = is_string($count->pv) ? json_decode($count->pv, true) : $count->pv;
            
            if (!is_array($arrayPv)) {
                continue;
            }

            // Check for enhanced PV structure first
            if (isset($arrayPv['waveforms']) && is_array($arrayPv['waveforms'])) {
                // Enhanced format: extract waveforms
                $waveforms = $arrayPv['waveforms'];
                $toeHeelArray = $waveforms[0] ?? [];
                $sideArray = $waveforms[1] ?? [];
            } elseif (isset($arrayPv[0]) && isset($arrayPv[1])) {
                // Legacy format: direct array access
                $toeHeelArray = $arrayPv[0];
                $sideArray = $arrayPv[1];
            } else {
                // Invalid format, skip this record
                continue;
            }

            // Skip if arrays are empty
            if (empty($toeHeelArray) && empty($sideArray)) {
                continue;
            }

            // Calculate median for each sensor array
            $toeHeelMedian = $this->getMedian($toeHeelArray);
            $sideMedian = $this->getMedian($sideArray);

            // Use object property access for better performance
            $position = $count->position ?? '';
            if ($position === 'L') {
                $toeheel_left_data[] = $toeHeelMedian;
                $side_left_data[] = $sideMedian;
            } elseif ($position === 'R') {
                $toeheel_right_data[] = $toeHeelMedian;
                $side_right_data[] = $sideMedian;
            }
        }

        $datasets = [
            [
                'x' => 'Toe-Heel Left',
                'y' => $this->getBoxplotSummary($toeheel_left_data)
            ],
            [
                'x' => 'Toe-Heel Right',
                'y' => $this->getBoxplotSummary($toeheel_right_data)
            ],
            [
                'x' => 'Side Left',
                'y' => $this->getBoxplotSummary($side_left_data)
            ],
            [
                'x' => 'Side Right',
                'y' => $this->getBoxplotSummary($side_right_data)
            ],
        ];

        // Filter out any datasets that returned null (no data)
        $filteredDatasets = array_filter($datasets, fn($d) => $d['y'] !== null);

        $performanceData = [
            'labels' => ['Toe-Heel Left', 'Toe-Heel Right', 'Side Left', 'Side Right'],
            'datasets' => array_values($filteredDatasets), // Pass only the valid data
        ];

        // Dispatch the event to the frontend to update the chart
        $this->dispatch('refresh-performance-chart', [
            'performanceData' => $performanceData,
        ]);
    }

    /**
     * Generate Duration Chart Data
     * Categorizes batch processing times by machine
     */
    private function generateDurationChart($data)
    {
        // Filter data with duration information
        $durationData = $data->filter(function($record) {
            return !is_null($record->duration) 
                && !is_null($record->mechine);
        });

        // Initialize counters for each machine (1-4)
        $machines = [
            1 => ['too_early_max' => 0, 'too_early_min' => 0, 'on_time' => 0, 'on_time_manual' => 0],
            2 => ['too_early_max' => 0, 'too_early_min' => 0, 'on_time' => 0, 'on_time_manual' => 0],
            3 => ['too_early_max' => 0, 'too_early_min' => 0, 'on_time' => 0, 'on_time_manual' => 0],
            4 => ['too_early_max' => 0, 'too_early_min' => 0, 'on_time' => 0, 'on_time_manual' => 0],
        ];

        // Batch process all records
        foreach ($durationData as $record) {
            $duration = (float) $record->duration;
            $machine = (int) $record->mechine;

            if (!isset($machines[$machine])) {
                continue;
            }

            // Categorize based on duration - optimized conditionals
            if ($duration < 10) {
                $machines[$machine]['too_early_max']++;
            } elseif ($duration < 13) {
                $machines[$machine]['too_early_min']++;
            } elseif ($duration <= 16) { // Combined condition
                $machines[$machine]['on_time']++;
            } else {
                $machines[$machine]['on_time_manual']++;
            }
        }

        // Prepare data for chart - optimized array construction
        $chartData = [
            'categories' => ['Machine 1', 'Machine 2', 'Machine 3', 'Machine 4'],
            'series' => [
                [
                    'name' => 'Too early (< 10s)',
                    'data' => [$machines[1]['too_early_max'], $machines[2]['too_early_max'], $machines[3]['too_early_max'], $machines[4]['too_early_max']],
                    'color' => '#ef4444'
                ],
                [
                    'name' => 'Too early (<13s)',
                    'data' => [$machines[1]['too_early_min'], $machines[2]['too_early_min'], $machines[3]['too_early_min'], $machines[4]['too_early_min']],
                    'color' => '#e8e231ff'
                ],
                [
                    'name' => 'On time (13-16s)',
                    'data' => [$machines[1]['on_time'], $machines[2]['on_time'], $machines[3]['on_time'], $machines[4]['on_time']],
                    'color' => '#22c55e'
                ],
                [
                    'name' => 'Too Late (> 16s)',
                    'data' => [$machines[1]['on_time_manual'], $machines[2]['on_time_manual'], $machines[3]['on_time_manual'], $machines[4]['on_time_manual']],
                    'color' => '#f97316'
                ],
            ],
        ];

        // Dispatch the event to the frontend
        $this->dispatch('refresh-duration-chart', [
            'durationData' => $chartData,
        ]);
    }

    /**
     * Generate Pressure Summary Chart Data
     * Categorizes pressure readings by machine
     */
    private function generatePressureSummaryChart($data)
    {
        // Filter data with pressure information
        $pressureData = $data->filter(function($record) {
            return !is_null($record->pv) 
                && !is_null($record->mechine);
        });

        // Initialize counters for each machine (1-4)
        $machines = [
            1 => ['out_standard' => 0, 'warning' => 0, 'in_standard' => 0, 'high_pressure' => 0],
            2 => ['out_standard' => 0, 'warning' => 0, 'in_standard' => 0, 'high_pressure' => 0],
            3 => ['out_standard' => 0, 'warning' => 0, 'in_standard' => 0, 'high_pressure' => 0],
            4 => ['out_standard' => 0, 'warning' => 0, 'in_standard' => 0, 'high_pressure' => 0],
        ];

        foreach ($pressureData as $record) {
            $machine = (int) $record->mechine;

            if (!isset($machines[$machine])) {
                continue;
            }

            // Parse the PV data
            $arrayPv = is_string($record->pv) ? json_decode($record->pv, true) : $record->pv;
            
            if (!is_array($arrayPv)) {
                continue;
            }
            
            // Extract waveforms based on format - using repeatWaveform for consistency with raw.blade.php
            if (isset($arrayPv['waveforms']) && is_array($arrayPv['waveforms'])) {
                $toeHeelArray = $this->repeatWaveform($arrayPv['waveforms'][0] ?? [], $arrayPv['timestamps'] ?? [], (int)($record->duration ?? 0));
                $sideArray = $this->repeatWaveform($arrayPv['waveforms'][1] ?? [], $arrayPv['timestamps'] ?? [], (int)($record->duration ?? 0));
            } elseif (isset($arrayPv[0]) && isset($arrayPv[1])) {
                // Old format without timestamps - use raw arrays
                $toeHeelArray = $arrayPv[0];
                $sideArray = $arrayPv[1];
            } else {
                continue;
            }

            // Skip if no data
            if (empty($toeHeelArray) && empty($sideArray)) {
                continue;
            }

            // Calculate median pressure for this record - optimized merge
            $allPressureValues = array_merge($toeHeelArray, $sideArray);
            $medianPressure = $this->getMedian($allPressureValues);

            // Categorize based on pressure value - optimized conditionals
            if ($medianPressure < 20) {
                $machines[$machine]['out_standard']++;
            } elseif ($medianPressure < 30) {
                $machines[$machine]['warning']++;
            } elseif ($medianPressure <= 45) {
                $machines[$machine]['in_standard']++;
            } else {
                $machines[$machine]['high_pressure']++;
            }
        }

        // Prepare data for chart - optimized array construction
        $chartData = [
            'categories' => ['Machine 1', 'Machine 2', 'Machine 3', 'Machine 4'],
            'series' => [
                [
                    'name' => 'Out Standard (< 20 kg)',
                    'data' => [$machines[1]['out_standard'], $machines[2]['out_standard'], $machines[3]['out_standard'], $machines[4]['out_standard']],
                    'color' => '#ef4444'
                ],
                [
                    'name' => 'Warning (< 30 kg)',
                    'data' => [$machines[1]['warning'], $machines[2]['warning'], $machines[3]['warning'], $machines[4]['warning']],
                    'color' => '#eab308'
                ],
                [
                    'name' => 'In Standard (30-45 kg)',
                    'data' => [$machines[1]['in_standard'], $machines[2]['in_standard'], $machines[3]['in_standard'], $machines[4]['in_standard']],
                    'color' => '#22c55e'
                ],
                [
                    'name' => 'High Pressure (> 45 kg)',
                    'data' => [$machines[1]['high_pressure'], $machines[2]['high_pressure'], $machines[3]['high_pressure'], $machines[4]['high_pressure']],
                    'color' => '#f97316'
                ],
            ],
        ];

        // Dispatch the event to the frontend
        $this->dispatch('refresh-pressure-summary-chart', [
            'pressureData' => $chartData,
        ]);
    }

    /** 
     * Make function for get emergency press count
     * get data from table ins_dwp_counts where duration < 13 sec
     * Returns array with counts grouped by machine
     */
    public function getEmergencyPressCount(){
        $start = Carbon::parse($this->start_at);
        $end = Carbon::parse($this->end_at)->endOfDay();

        $query = InsDwpCount::whereBetween("created_at", [$start, $end])
            ->where('duration', '<', 13)
            ->selectRaw('mechine, COUNT(*) as count')
            ->groupBy('mechine');

        if ($this->line) {
            $query->where("line", "like", "%" . strtoupper(trim($this->line)) . "%");
        }

        if (!empty($this->position)) {
            $query->where('position', $this->position);
        }

        if (!empty($this->machine)) {
            $query->where('mechine', $this->machine);
        }

        $results = $query->get()->pluck('count', 'mechine')->toArray();
        
        // Ensure all machines (1-4) are present in the result
        return [
            1 => $results[1] ?? 0,
            2 => $results[2] ?? 0,
            3 => $results[3] ?? 0,
            4 => $results[4] ?? 0,
        ];
    }

    /**
     * Navigate to raw data view with filters
     */
    public function navigateToRaw($filters = [])
    {
        $params = ['view' => 'raw'];
        
        // Add provided filters
        foreach ($filters as $key => $value) {
            if (!empty($value)) {
                // Format dates for datetime-local inputs
                if ($key === 'start_at') {
                    // If date only (no time), append T00:00
                    if (!str_contains($value, 'T')) {
                        $value = $value . 'T00:00';
                    }
                } elseif ($key === 'end_at') {
                    // If date only (no time), append T23:59
                    if (!str_contains($value, 'T')) {
                        $value = $value . 'T23:59';
                    }
                }
                $params[$key] = $value;
            }
        }
        
        // Build query string
        $queryString = http_build_query($params);
        
        return redirect('/insights/dwp/data?' . $queryString);
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
        <div class="grid grid-cols-2 lg:flex gap-3">
            <div>
                <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Line") }}</label>
                <x-select wire:model.live="line" wire:change="dispatch('updated')" class="w-full lg:w-32">
                    <option value="g5">G5</option>
                    <option value="g3">G3</option>
                    <option value="g2">G2</option>
                </x-select>
            </div>
            <div>
                <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Machine") }}</label>
                <x-select wire:model.live="machine" wire:change="dispatch('updated')" class="w-full lg:w-32">
                    <option value="">All</option>
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                </x-select>
            </div>
            <div>
                <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Position") }}</label>
                <x-select wire:model.live="position" wire:change="dispatch('updated')" class="w-full lg:w-32">
                    <option value="">All</option>
                    <option value="L">Left</option>
                    <option value="R">Right</option>
                </x-select>
            </div>
        </div>
    </div>
  </div>
  <div class="overflow-visible">
    <div class="grid grid-cols-1 gap-2 md:grid-cols-1 md:gap-2">
         <!-- chart section type boxplot -->
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-4">
            <div
                x-data="{
                    performanceChart: null,

                    // This function will now handle all chart creation/updates
                    initOrUpdateChart(performanceData) {
                        const chartEl = this.$refs.chartContainer; // Get element using x-ref
                        if (!chartEl) {
                            return;
                        }

                        const datasets = performanceData.datasets || [];

                        // --- FIX 1: Corrected Data Transformation ---
                        // ApexCharts boxplot expects: { x: 'label', y: [min, q1, median, q3, max] }
                        const transformedData = datasets
                            .filter(dataset => {
                                return dataset &&
                                    dataset.hasOwnProperty('x') &&
                                    dataset.hasOwnProperty('y') &&
                                    Array.isArray(dataset.y) &&
                                    dataset.y.length === 5 &&
                                    dataset.y.every(val => typeof val === 'number' && !isNaN(val));
                            })
                            .map(dataset => {
                                return {
                                    x: dataset.x, // The category name (e.g., 'Toe-Heel Left')
                                    y: dataset.y  // The 5-point array [min, q1, median, q3, max]
                                };
                            });

                        const hasValidData = transformedData.length > 0;

                        // --- FIX 2: Robust Update Logic ---
                        // Always destroy the old chart instance before creating a new one.
                        if (this.performanceChart) {
                            this.performanceChart.destroy();
                        }

                        const options = {
                            // --- FIX 3: Corrected Series Definition ---
                            series: [{
                                name: 'Performance',
                                data: transformedData
                            }],
                            chart: {
                                type: 'boxPlot',
                                height: 350,
                                toolbar: { show: true },
                                animations: {
                                    enabled: true,
                                    speed: 350 // Faster animation for updates
                                }
                            },
                            title: {
                                text: 'DWP Machine Performance'
                            },
                            xaxis: {
                                // --- FIX 4: Removed Redundant Categories ---
                                type: 'category',
                            },
                            yaxis: {
                                title: { text: 'Pressure' },
                                labels: {
                                    formatter: (val) => { return val.toFixed(2) }
                                }
                            },
                            tooltip: {
                                y: {
                                    formatter: (val) => { return val.toFixed(2) }
                                }
                            },
                            noData: hasValidData ? undefined : {
                                text: 'No data available',
                                align: 'center',
                                verticalAlign: 'middle',
                            }
                        };

                        this.performanceChart = new ApexCharts(chartEl, options);
                        this.performanceChart.render();
                    }
                }"
                x-init="
                    // Listen for the Livewire event
                    $wire.on('refresh-performance-chart', function(payload) {
                        let data = payload;
                        if (payload && payload.detail) data = payload.detail;
                        if (Array.isArray(payload) && payload.length) data = payload[0];
                        if (payload && payload[0] && payload[0].performanceData) data = payload[0];

                        const performanceData = data?.performanceData;
                        if (!performanceData) {
                            return;
                        }

                        try {
                            // Call our Alpine method
                            initOrUpdateChart(performanceData);
                        } catch (e) {
                            // Error handling
                        }
                    });

                    // Initial load - dispatch the updated event to fetch data and render chart
                    $wire.$dispatch('updated');
                " >
                <div id="performanceChart" x-ref="chartContainer" wire:ignore></div>
            </div>
        </div>
    </div>
    <!-- summary pressure  -->
    <h1 class="text-xl text-neutral-900 dark:text-neutral-100 font-semibold mb-4 mt-5">Summary Pressure Readings</h1>
    <div class="grid grid-cols-6 gap-2 mt-2">
        <!-- Pressure Stacked Bar Chart -->
         <div class="col-span-4 bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-4">
            <div
                x-data="{
                    pressureSummaryChart: null,

                    initOrUpdatePressureSummaryChart(pressureData) {
                        const chartEl = this.$refs.pressureSummaryChartContainer;
                        if (!chartEl) {
                            return;
                        }

                        const series = pressureData.series || [];
                        const categories = pressureData.categories || [];

                        // Destroy old chart if exists
                        if (this.pressureSummaryChart) {
                            this.pressureSummaryChart.destroy();
                        }

                        const options = {
                            series: series,
                            chart: {
                                type: 'bar',
                                height: 350,
                                stacked: true,
                                toolbar: { show: true },
                                animations: {
                                    enabled: true,
                                    speed: 350
                                },
                                events: {
                                    dataPointSelection:  function(event, chartContext, config) {
                                        const machines = ['1', '2', '3', '4'];
                                        const selectedMachine = machines[config.dataPointIndex];
                                        const currentLine = '{{ $line }}';
                                        const currentPosition = '{{ $position }}';
                                        const status = ['<20', '<30', '30-45', '>45'][config.seriesIndex];
                                        
                                        // Use Livewire navigation
                                        window.dispatchEvent(new CustomEvent('navigate-to-raw', {
                                            detail: {
                                                mechine: selectedMachine,
                                                line: currentLine,
                                                position: currentPosition,
                                                start_at: $wire.start_at,
                                                end_at: $wire.end_at,
                                                status: status
                                            }
                                        }));
                                    }
                                }
                            },
                            plotOptions: {
                                bar: {
                                    horizontal: true,
                                    dataLabels: {
                                        total: {
                                            enabled: true,
                                            offsetX: 0,
                                            style: {
                                                fontSize: '13px',
                                                fontWeight: 900
                                            }
                                        }
                                    }
                                },
                            },
                            stroke: {
                                width: 1,
                                colors: ['#fff']
                            },
                            title: {
                                text: 'Pressure Readings Summary by Machine'
                            },
                            xaxis: {
                                categories: categories,
                                title: {
                                    text: 'Count Pressure Readings'
                                }
                            },
                            yaxis: {
                                title: {
                                    text: 'Machine'
                                }
                            },
                            tooltip: {
                                y: {
                                    formatter: function (val) {
                                        return val + ' Readings'
                                    }
                                }
                            },
                            fill: {
                                opacity: 1
                            },
                            legend: {
                                position: 'top',
                                horizontalAlign: 'left',
                                offsetX: 40
                            },
                            colors: series.map(s => s.color)
                        };

                        this.pressureSummaryChart = new ApexCharts(chartEl, options);
                        this.pressureSummaryChart.render();
                    }
                }"
                x-init="
                    $wire.on('refresh-pressure-summary-chart', function(payload) {
                        let data = payload;
                        if (payload && payload.detail) data = payload.detail;
                        if (Array.isArray(payload) && payload.length) data = payload[0];
                        if (payload && payload[0] && payload[0].pressureData) data = payload[0];

                        const pressureData = data?.pressureData;
                        if (!pressureData) {
                            return;
                        }

                        try {
                            initOrUpdatePressureSummaryChart(pressureData);
                        } catch (e) {
                            // Error handling
                        }
                    });
                "
            >
                <div x-ref="pressureSummaryChartContainer" wire:ignore></div>
            </div>
         </div>
        <!-- Pressure Pie Chart - Percentage Distribution -->
         <div class="col-span-2 bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-4">
            <div
                x-data="{
                    pressurePieChart: null,

                    initOrUpdatePressurePieChart(pressureData) {
                        const chartEl = this.$refs.pressurePieChartContainer;
                        if (!chartEl) {
                            return;
                        }

                        const series = pressureData.series || [];
                        
                        // Calculate totals for each category across all machines
                        const categoryTotals = {};
                        const categoryColors = {};
                        series.forEach(category => {
                            const total = category.data.reduce((sum, val) => sum + val, 0);
                            // Always include all categories, even if total is 0
                            categoryTotals[category.name] = total;
                            categoryColors[category.name] = category.color;
                        });

                        const labels = Object.keys(categoryTotals);
                        const values = Object.values(categoryTotals);
                        const colors = labels.map(label => categoryColors[label]);

                        // Destroy old chart if exists
                        if (this.pressurePieChart) {
                            this.pressurePieChart.destroy();
                        }

                        const options = {
                            series: values,
                            chart: {
                                type: 'pie',
                                height: 350,
                                animations: {
                                    enabled: true,
                                    speed: 350
                                },
                                events: {
                                    dataPointSelection:  function(event, chartContext, config) {
                                        const categories = ['<20', '<30', '30-45', '>45'];
                                        const selectedCategory = categories[config.dataPointIndex];
                                        
                                        // Use Livewire navigation
                                        window.dispatchEvent(new CustomEvent('navigate-to-raw', {
                                            detail: {
                                                status: selectedCategory,
                                                start_at: $wire.start_at,
                                                end_at: $wire.end_at
                                            }
                                        }));
                                    }
                                }
                            },
                            labels: labels,
                            colors: colors,
                            title: {
                                text: 'Pressure Summary (%)',
                                align: 'center'
                            },
                            tooltip: {
                                y: {
                                    formatter: function (val) {
                                        return val + ' Readings'
                                    }
                                }
                            },
                            legend: {
                                position: 'bottom',
                                horizontalAlign: 'center'
                            },
                            dataLabels: {
                                enabled: true,
                                formatter: function (val) {
                                    return val.toFixed(1) + '%'
                                }
                            },
                            responsive: [{
                                breakpoint: 480,
                                options: {
                                    chart: {
                                        height: 300
                                    },
                                    legend: {
                                        position: 'bottom'
                                    }
                                }
                            }]
                        };

                        this.pressurePieChart = new ApexCharts(chartEl, options);
                        this.pressurePieChart.render();
                    }
                }"
                x-init="
                    $wire.on('refresh-pressure-summary-chart', function(payload) {
                        let data = payload;
                        if (payload && payload.detail) data = payload.detail;
                        if (Array.isArray(payload) && payload.length) data = payload[0];
                        if (payload && payload[0] && payload[0].pressureData) data = payload[0];

                        const pressureData = data?.pressureData;
                        if (!pressureData) {
                            return;
                        }

                        try {
                            initOrUpdatePressurePieChart(pressureData);
                        } catch (e) {
                            // Error handling
                        }
                    });
                "
            >
                <div x-ref="pressurePieChartContainer" wire:ignore></div>
            </div>
         </div>
    </div>
    <h1 class="text-xl text-neutral-900 dark:text-neutral-100 font-semibold mb-4 mt-5">Summary Press Time</h1>
    <div class="grid grid-cols-4 gap-2 overflow-visible">
        <div class="relative bg-neutral-200 dark:bg-neutral-800 shadow sm:rounded-lg p-4"
             x-data="{ showTooltip: false }"
             @mouseenter="showTooltip = true"
             @mouseleave="showTooltip = false">
            <p class="text-neutral-600">Machine 1</p>
            <h3 class="font-bold text-2xl text-neutral-600">Emergency Pressed <br><span class="text-4xl font-bold text-red-600">{{ $emergencyCounts[1] }}</span></h3>
            <div x-show="showTooltip"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 transform scale-95"
                 x-transition:enter-end="opacity-100 transform scale-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 transform scale-100"
                 x-transition:leave-end="opacity-0 transform scale-95"
                 class="absolute left-1/2 -translate-x-1/2 bottom-full mb-2 z-50 px-3 py-2 text-xs text-white bg-gray-900 rounded shadow-lg whitespace-nowrap">
                Number of emergency presses for Machine 1 (press time <10 & <13 sec)
            </div>
        </div>
        <div class="relative bg-neutral-200 dark:bg-neutral-800 shadow sm:rounded-lg p-4"
             x-data="{ showTooltip: false }"
             @mouseenter="showTooltip = true"
             @mouseleave="showTooltip = false">
            <p class="text-neutral-600">Machine 2</p>
            <h3 class="font-bold text-2xl text-neutral-600">Emergency Pressed <br><span class="text-4xl font-bold text-red-600">{{ $emergencyCounts[2] }}</span></h3>
            <div x-show="showTooltip"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 transform scale-95"
                 x-transition:enter-end="opacity-100 transform scale-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 transform scale-100"
                 x-transition:leave-end="opacity-0 transform scale-95"
                 class="absolute left-1/2 -translate-x-1/2 bottom-full mb-2 z-50 px-3 py-2 text-xs text-white bg-gray-900 rounded shadow-lg whitespace-nowrap">
                Number of emergency presses for Machine 2 (press time <10 & <13 sec)
            </div>
        </div>
        <div class="relative bg-neutral-200 dark:bg-neutral-800 shadow sm:rounded-lg p-4"
             x-data="{ showTooltip: false }"
             @mouseenter="showTooltip = true"
             @mouseleave="showTooltip = false">
            <p class="text-neutral-600">Machine 3</p>
            <h3 class="font-bold text-2xl text-neutral-600">Emergency Pressed <br><span class="text-4xl font-bold text-red-600">{{ $emergencyCounts[3] }}</span></h3>
            <div x-show="showTooltip"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 transform scale-95"
                 x-transition:enter-end="opacity-100 transform scale-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 transform scale-100"
                 x-transition:leave-end="opacity-0 transform scale-95"
                 class="absolute left-1/2 -translate-x-1/2 bottom-full mb-2 z-50 px-3 py-2 text-xs text-white bg-gray-900 rounded shadow-lg whitespace-nowrap">
                Number of emergency presses for Machine 3 (press time <10 & <13 sec)
            </div>
        </div>
        <div class="relative bg-neutral-200 dark:bg-neutral-800 shadow sm:rounded-lg p-4"
             x-data="{ showTooltip: false }"
             @mouseenter="showTooltip = true"
             @mouseleave="showTooltip = false">
            <p class="text-neutral-600">Machine 4</p>
            <h3 class="font-bold text-2xl text-neutral-600">Emergency Pressed <br><span class="text-4xl font-bold text-red-600">{{ $emergencyCounts[4] }}</span></h3>
            <div x-show="showTooltip"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 transform scale-95"
                 x-transition:enter-end="opacity-100 transform scale-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 transform scale-100"
                 x-transition:leave-end="opacity-0 transform scale-95"
                 class="absolute left-1/2 -translate-x-1/2 bottom-full mb-2 z-50 px-3 py-2 text-xs text-white bg-gray-900 rounded shadow-lg whitespace-nowrap">
                Number of emergency presses for Machine 4 (press time <10 & <13 sec)
            </div>
        </div>
    </div>
    <div class="grid grid-cols-6 gap-2 mt-2">
        <!-- Duration Pie Chart - Percentage Distribution -->
        <div class="col-span-2 bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-4">
            <div
                x-data="{
                    pieChart: null,
                    initOrUpdatePieChart(durationData) {
                        const chartEl = this.$refs.pieChartContainer;
                        if (!chartEl) {
                            return;
                        }

                        const series = durationData.series || [];
                        
                        // Calculate totals for each category across all machines
                        const categoryTotals = {};
                        const categoryColors = {};
                        series.forEach(category => {
                            const total = category.data.reduce((sum, val) => sum + val, 0);
                            if (total > 0) {
                                categoryTotals[category.name] = total;
                                categoryColors[category.name] = category.color;
                            }
                        });

                        const labels = Object.keys(categoryTotals);
                        const values = Object.values(categoryTotals);
                        const colors = labels.map(label => categoryColors[label]);

                        // Destroy old chart if exists
                        if (this.pieChart) {
                            this.pieChart.destroy();
                        }

                        const options = {
                            series: values,
                            chart: {
                                type: 'pie',
                                height: 350,
                                animations: {
                                    enabled: true,
                                    speed: 350
                                },
                                events: {
                                    dataPointSelection:  function(event, chartContext, config) {
                                        const categories = ['<10', '10-13', '13-16', '>16'];
                                        const selectedCategory = categories[config.dataPointIndex];
                                        const currentLine = '{{ $line }}';
                                        
                                        // Use Livewire navigation
                                        window.dispatchEvent(new CustomEvent('navigate-to-raw', {
                                            detail: {
                                                presstime: selectedCategory,
                                                line: currentLine,
                                                start_at: $wire.start_at,
                                                end_at: $wire.end_at
                                            }
                                        }));
                                    }
                                }
                            },
                            labels: labels,
                            colors: colors,
                            title: {
                                text: 'Press Time Summary (%)',
                                align: 'center'
                            },
                            tooltip: {
                                y: {
                                    formatter: function (val) {
                                        return val + ' Cycles'
                                    }
                                }
                            },
                            legend: {
                                position: 'bottom',
                                horizontalAlign: 'center'
                            },
                            dataLabels: {
                                enabled: true,
                                formatter: function (val) {
                                    return val.toFixed(1) + '%'
                                }
                            },
                            responsive: [{
                                breakpoint: 480,
                                options: {
                                    chart: {
                                        height: 300
                                    },
                                    legend: {
                                        position: 'bottom'
                                    }
                                }
                            }]
                        };

                        this.pieChart = new ApexCharts(chartEl, options);
                        this.pieChart.render();
                    }
                }"
                x-init="
                    $wire.on('refresh-duration-chart', function(payload) {
                        let data = payload;
                        if (payload && payload.detail) data = payload.detail;
                        if (Array.isArray(payload) && payload.length) data = payload[0];
                        if (payload && payload[0] && payload[0].durationData) data = payload[0];

                        const durationData = data?.durationData;
                        if (!durationData) {
                            return;
                        }

                        try {
                            initOrUpdatePieChart(durationData);
                        } catch (e) {
                            // Error handling
                        }
                    });
                "
            >
                <div x-ref="pieChartContainer" wire:ignore></div>
            </div>
        </div>
         <!-- Duration Chart - Stacked Bar Chart -->
        <div class="col-span-4 bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-4">
        <div class="grid grid-cols-1 mt-4">
            <h1 class="font-bold text-xl">Press Time By Machine</h1>
        </div>    
        <div class="mt-4"
                x-data="{
                    durationChart: null,

                    initOrUpdateDurationChart(durationData) {
                        const chartEl = this.$refs.durationChartContainer;
                        if (!chartEl) {
                            return;
                        }

                        const series = durationData.series || [];
                        const categories = durationData.categories || [];

                        // Destroy old chart if exists
                        if (this.durationChart) {
                            this.durationChart.destroy();
                        }

                        const options = {
                            series: series,
                            chart: {
                                type: 'bar',
                                height: 350,
                                stacked: true,
                                toolbar: { show: true },
                                animations: {
                                    enabled: true,
                                    speed: 350
                                },
                                events:  {
                                    dataPointSelection: function(event, chartContext, config) {
                                        const machineIndex = config.dataPointIndex; // Which machine bar was clicked
                                        const seriesIndex = config.seriesIndex; // Which segment/category was clicked
                                        
                                        const machineName = categories[machineIndex];
                                        const machineNumber = machineName.split(' ')[1];
                                        
                                        // Map series index to press time category
                                        const presstimeCategories = ['<10', '10-13', '13-16', '>16'];
                                        const presstime = presstimeCategories[seriesIndex];

                                        const currentLine = '{{ $line }}';
                                        
                                        // Use Livewire navigation
                                        window.dispatchEvent(new CustomEvent('navigate-to-raw', {
                                            detail: {
                                                mechine: machineNumber,
                                                presstime: presstime,
                                                line: currentLine,
                                                start_at: $wire.start_at,
                                                end_at: $wire.end_at
                                            }
                                        }));
                                    }
                                }
                            },
                            plotOptions: {
                                bar: {
                                    horizontal: true,
                                    dataLabels: {
                                        total: {
                                            enabled: true,
                                            offsetX: 0,
                                            style: {
                                                fontSize: '13px',
                                                fontWeight: 900
                                            }
                                        }
                                    }
                                },
                            },
                            stroke: {
                                width: 1,
                                colors: ['#fff']
                            },
                            xaxis: {
                                categories: categories,
                                title: {
                                    text: 'Cycle Count'
                                }
                            },
                            yaxis: {
                                title: {
                                    text: 'Machine'
                                }
                            },
                            tooltip: {
                                y: {
                                    formatter: function (val) {
                                        return val + ' Cycles'
                                    }
                                }
                            },
                            fill: {
                                opacity: 1
                            },
                            legend: {
                                position: 'top',
                                horizontalAlign: 'left',
                                offsetX: 40
                            },
                            colors: series.map(s => s.color)
                        };

                        this.durationChart = new ApexCharts(chartEl, options);
                        this.durationChart.render();
                    }
                }"
                x-init="
                    $wire.on('refresh-duration-chart', function(payload) {
                        let data = payload;
                        if (payload && payload.detail) data = payload.detail;
                        if (Array.isArray(payload) && payload.length) data = payload[0];
                        if (payload && payload[0] && payload[0].durationData) data = payload[0];

                        const durationData = data?.durationData;
                        if (!durationData) {
                            return;
                        }

                        try {
                            initOrUpdateDurationChart(durationData);
                        } catch (e) {
                            // Error handling
                        }
                    });
                "
            >
                <div x-ref="durationChartContainer" wire:ignore></div>
            </div>
        </div>
    </div>
  </div>
</div>

@script
<script>
// Global event listener for chart navigation
window.addEventListener('navigate-to-raw', function(event) {
    const filters = event.detail;
    $wire.navigateToRaw(filters);
});
</script>
@endscript
