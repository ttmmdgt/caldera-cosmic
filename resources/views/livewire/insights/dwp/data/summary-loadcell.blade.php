<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use App\Models\InsDwpLoadcell;
use App\Traits\HasDateRangeFilter;
use Carbon\Carbon;

new #[Layout("layouts.app")] class extends Component {
    use WithPagination;
    use HasDateRangeFilter;

    #[Url]
    public string $start_at = "";

    #[Url]
    public string $end_at = "";

    #[Url]
    public string $line = "";

    #[Url]
    public string $plant = "";

    #[Url]
    public string $machine = "";

    #[Url]
    public string $position = "";

    #[Url]
    public string $result = "";

    public string $view = "summary-loadcell";

    public function mount()
    {
        if (! $this->start_at || ! $this->end_at) {
            $this->setToday();
        }

        // update menu
        $this->dispatch("update-menu", $this->view);
    }

    /**
     * Get filtered loadcell query
     */
    private function getLoadcellQuery()
    {
        $start = Carbon::parse($this->start_at);
        $end = Carbon::parse($this->end_at)->endOfDay();

        $query = InsDwpLoadcell::whereBetween("created_at", [$start, $end]);

        if ($this->plant) {
            $query->where("plant", "like", "%" . strtoupper(trim($this->plant)) . "%");
        }

        if ($this->line) {
            $query->where("line", strtoupper(trim($this->line)));
        }

        if ($this->machine) {
            $query->where("machine_name", $this->machine);
        }

        if ($this->position) {
            $query->where("position", "like", "%" . $this->position . "%");
        }

        if ($this->result) {
            $query->where("result", $this->result);
        }

        return $query;
    }

    /**
     * Option 1: Overview Cards (KPIs)
     */
    private function getOverviewStats(): array
    {
        $query = $this->getLoadcellQuery();

        $totalTests = $query->count();
        $passedTests = (clone $query)->where('result', 'std')->count();
        $failedTests = $totalTests - $passedTests;
        $passRate = $totalTests > 0 ? round(($passedTests / $totalTests) * 100, 1) : 0;

        // Calculate average peak pressure
        $avgPeakPressure = 0;
        $peakPressures = [];
        
        (clone $query)->chunk(100, function ($records) use (&$peakPressures) {
            foreach ($records as $record) {
                $data = json_decode($record->loadcell_data, true);
                if (isset($data['metadata']['max_peak_pressure'])) {
                    $peakPressures[] = $data['metadata']['max_peak_pressure'];
                }
            }
        });

        if (count($peakPressures) > 0) {
            $avgPeakPressure = round(array_sum($peakPressures) / count($peakPressures), 2);
        }

        // Active machines count
        $activeMachines = (clone $query)->distinct('machine_name')->count('machine_name');

        // Tests by operator
        $operatorCounts = (clone $query)
            ->selectRaw('operator, COUNT(*) as count')
            ->whereNotNull('operator')
            ->groupBy('operator')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->pluck('count', 'operator')
            ->toArray();

        return [
            'total_tests' => $totalTests,
            'passed_tests' => $passedTests,
            'failed_tests' => $failedTests,
            'pass_rate' => $passRate,
            'avg_peak_pressure' => $avgPeakPressure,
            'active_machines' => $activeMachines,
            'operator_counts' => $operatorCounts,
        ];
    }

    /**
     * Option 3: Pressure Analysis - Bar Chart Data
     * Returns average pressure for each sensor
     */
    private function getPressureBoxplotData(): array
    {
        $query = $this->getLoadcellQuery();
        
        // Initialize arrays for each sensor
        $sensorData = [
            'C1' => [], 'C2' => [], 'H1' => [], 'L1' => [], 
            'L2' => [], 'M1' => [], 'M2' => [], 'T1' => []
        ];

        $positionSuffix = '';
        if ($this->position) {
            $positionSuffix = '_' . strtoupper(substr($this->position, 0, 1));
        }

        // Collect all peak pressures from each sensor
        $query->chunk(100, function ($records) use (&$sensorData, $positionSuffix) {
            foreach ($records as $record) {
                $data = json_decode($record->loadcell_data, true);
                if (!isset($data['metadata']['cycles'])) continue;

                foreach ($data['metadata']['cycles'] as $cycle) {
                    if (!isset($cycle['sensors'])) continue;

                    foreach ($cycle['sensors'] as $sensorName => $values) {
                        // Extract base sensor name (C1, C2, H1, etc.)
                        $baseName = preg_replace('/_[LR]$/', '', $sensorName);
                        
                        // Filter by position if specified
                        if ($positionSuffix && !str_ends_with($sensorName, $positionSuffix)) {
                            continue;
                        }

                        if (isset($sensorData[$baseName]) && is_array($values)) {
                            foreach ($values as $value) {
                                if (is_numeric($value) && $value > 0) {
                                    $sensorData[$baseName][] = (float) $value;
                                }
                            }
                        }
                    }
                }
            }
        });

        // Calculate average pressure for each sensor
        $barChartData = [];
        foreach ($sensorData as $sensor => $values) {
            if (count($values) > 0) {
                $barChartData[$sensor] = round(array_sum($values) / count($values), 2);
            } else {
                $barChartData[$sensor] = 0;
            }
        }

        return $barChartData;
    }

    /**
     * Option 4: Quality Control Metrics - Bar Chart Data
     */
    private function getQualityMetrics(): array
    {
        $query = $this->getLoadcellQuery();

        // Pass/Fail Distribution
        $passFailData = (clone $query)
            ->selectRaw('result, COUNT(*) as count')
            ->groupBy('result')
            ->get()
            ->pluck('count', 'result')
            ->toArray();

        // Tests by Machine
        $machineData = (clone $query)
            ->selectRaw('machine_name, COUNT(*) as total, 
                SUM(CASE WHEN result = "std" THEN 1 ELSE 0 END) as passed,
                SUM(CASE WHEN result != "std" THEN 1 ELSE 0 END) as failed')
            ->groupBy('machine_name')
            ->orderBy('machine_name')
            ->get()
            ->map(function ($item) {
                return [
                    'machine' => $item->machine_name,
                    'total' => $item->total,
                    'passed' => $item->passed,
                    'failed' => $item->failed,
                    'pass_rate' => $item->total > 0 ? round(($item->passed / $item->total) * 100, 1) : 0
                ];
            })
            ->toArray();

        // Tests by Position (Left vs Right)
        $positionData = (clone $query)
            ->selectRaw('position, COUNT(*) as total, 
                SUM(CASE WHEN result = "std" THEN 1 ELSE 0 END) as passed,
                SUM(CASE WHEN result != "std" THEN 1 ELSE 0 END) as failed')
            ->groupBy('position')
            ->get()
            ->map(function ($item) {
                return [
                    'position' => $item->position,
                    'total' => $item->total,
                    'passed' => $item->passed,
                    'failed' => $item->failed,
                    'pass_rate' => $item->total > 0 ? round(($item->passed / $item->total) * 100, 1) : 0
                ];
            })
            ->toArray();

        return [
            'pass_fail' => $passFailData,
            'by_machine' => $machineData,
            'by_position' => $positionData,
        ];
    }

    /**
     * Option 7: Time-Based Analysis - Bar Chart Data
     */
    private function getTimeBasedAnalysis(): array
    {
        $query = $this->getLoadcellQuery();

        // Hourly distribution
        $hourlyData = (clone $query)
            ->selectRaw('HOUR(created_at) as hour, COUNT(*) as count')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->pluck('count', 'hour')
            ->toArray();

        // Fill missing hours with 0
        $hourlyComplete = [];
        for ($i = 0; $i < 24; $i++) {
            $hourlyComplete[$i] = $hourlyData[$i] ?? 0;
        }

        // Daily distribution
        $dailyData = (clone $query)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => Carbon::parse($item->date)->format('M d'),
                    'count' => $item->count
                ];
            })
            ->toArray();

        // Average latency analysis
        $latencyData = [];
        (clone $query)->whereNotNull('recorded_at')->chunk(100, function ($records) use (&$latencyData) {
            foreach ($records as $record) {
                if ($record->created_at && $record->recorded_at) {
                    $created = Carbon::parse($record->created_at);
                    $recorded = Carbon::parse($record->recorded_at);
                    $latencySeconds = abs($created->diffInSeconds($recorded));
                    $latencyData[] = $latencySeconds;
                }
            }
        });

        $avgLatency = count($latencyData) > 0 ? round(array_sum($latencyData) / count($latencyData), 2) : 0;

        return [
            'hourly' => $hourlyComplete,
            'daily' => $dailyData,
            'avg_latency_seconds' => $avgLatency,
        ];
    }

    /**
     * Get summary table data grouped by plant and line with sensor details
     */
    private function getSummaryTableData(): array
    {
        $query = $this->getLoadcellQuery();

        // Get latest record for each plant-line-machine-position combination
        $records = $query->orderBy('created_at', 'DESC')->get();

        $summary = [];

        // Define sensor structure for each position
        $defaultSensorData = [
            'toe' => null,   // T1
            'heel' => null,  // H1
            'm1' => null,    // M1
            'm2' => null,    // M2
            'c1' => null,    // C1
            'c2' => null,    // C2
            'l1' => null,    // L1
            'l2' => null,    // L2
            'result' => null,
        ];

        foreach ($records as $record) {
            $plant = $record->plant ?? 'Unknown';
            $line = $record->line ?? 'Unknown';
            $machine = $record->machine_name ?? '0';
            $position = $record->position ?? 'Unknown';
            $result = $record->result ?? 'unknown';
            $timestamp = $record->created_at;

            // Create unique key for plant-line combination
            $key = $plant . '-' . $line;

            // Initialize if not exists
            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'plant' => $plant,
                    'line' => $line,
                    'latest_timestamp' => $timestamp,
                    'machines' => [
                        '1' => ['L' => $defaultSensorData, 'R' => $defaultSensorData],
                        '2' => ['L' => $defaultSensorData, 'R' => $defaultSensorData],
                        '3' => ['L' => $defaultSensorData, 'R' => $defaultSensorData],
                        '4' => ['L' => $defaultSensorData, 'R' => $defaultSensorData],
                    ]
                ];
            }

            // Update latest timestamp
            if ($timestamp > $summary[$key]['latest_timestamp']) {
                $summary[$key]['latest_timestamp'] = $timestamp;
            }

            // Determine position key (L or R)
            $posKey = strtoupper(substr($position, 0, 1));
            if (!in_array($posKey, ['L', 'R'])) {
                $posKey = 'L'; // default to Left if unclear
            }

            // Initialize machine if not exists
            if (!isset($summary[$key]['machines'][$machine])) {
                $summary[$key]['machines'][$machine] = ['L' => $defaultSensorData, 'R' => $defaultSensorData];
            }

            // Only process if not already set (we want latest record only)
            if ($summary[$key]['machines'][$machine][$posKey]['result'] === null) {
                $summary[$key]['machines'][$machine][$posKey]['result'] = ($result === 'std') ? 'OK' : 'NG';

                // Extract sensor peak values from loadcell_data
                $loadcellData = json_decode($record->loadcell_data, true);
                if (isset($loadcellData['metadata']['cycles'])) {
                    foreach ($loadcellData['metadata']['cycles'] as $cycle) {
                        if (!isset($cycle['sensors'])) continue;

                        foreach ($cycle['sensors'] as $sensorName => $values) {
                            // Get peak value from sensor data
                            $peakValue = is_array($values) ? max($values) : $values;

                            // Map sensor names to our structure
                            // Sensors may have suffixes like _L or _R for position
                            $baseName = preg_replace('/[_-]?[LR]$/i', '', $sensorName);
                            $baseName = strtoupper($baseName);

                            $sensorMap = [
                                'T1' => 'toe',
                                'H1' => 'heel',
                                'M1' => 'm1',
                                'M2' => 'm2',
                                'C1' => 'c1',
                                'C2' => 'c2',
                                'L1' => 'l1',
                                'L2' => 'l2',
                            ];

                            if (isset($sensorMap[$baseName])) {
                                $fieldName = $sensorMap[$baseName];
                                if ($summary[$key]['machines'][$machine][$posKey][$fieldName] === null) {
                                    $summary[$key]['machines'][$machine][$posKey][$fieldName] = round($peakValue);
                                }
                            }
                        }
                    }
                }
            }
        }

        // Sort by plant and line
        usort($summary, function ($a, $b) {
            if ($a['plant'] !== $b['plant']) {
                return strcmp($a['plant'], $b['plant']);
            }
            return strcmp($a['line'], $b['line']);
        });

        return $summary;
    }

    public function with(): array
    {
        $overview = $this->getOverviewStats();
        $pressureBoxplot = $this->getPressureBoxplotData();
        $qualityMetrics = $this->getQualityMetrics();
        $timeAnalysis = $this->getTimeBasedAnalysis();
        $summaryTable = $this->getSummaryTableData();

        // Inject chart rendering script
        $this->dispatch('charts-data-ready', [
            'pressureBoxplot' => $pressureBoxplot,
            'qualityMetrics' => $qualityMetrics,
            'timeAnalysis' => $timeAnalysis,
        ]);

        return [
            'overview' => $overview,
            'pressureBoxplot' => $pressureBoxplot,
            'qualityMetrics' => $qualityMetrics,
            'timeAnalysis' => $timeAnalysis,
            'summaryTable' => $summaryTable,
        ];
    }
}; ?>

<div>
    <!-- Filters Section -->
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
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Plant") }}</label>
                    <x-select wire:model.live="plant" class="w-full lg:w-32">
                        <option value="">{{ __("All") }}</option>
                        <option value="Plant G">G</option>
                        <option value="Plant A">A</option>
                    </x-select>
                </div>
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Line") }}</label>
                    <x-select wire:model.live="line" class="w-full lg:w-32">
                        <option value="">{{ __("All") }}</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                        <option value="4">4</option>
                        <option value="5">5</option>
                    </x-select>
                </div>
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Machine") }}</label>
                    <x-select wire:model.live="machine" class="w-full lg:w-32">
                        <option value="">{{ __("All") }}</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                        <option value="4">4</option>
                    </x-select>
                </div>
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Position") }}</label>
                    <x-select wire:model.live="position" class="w-full lg:w-32">
                        <option value="">{{ __("All") }}</option>
                        <option value="Left">Left</option>
                        <option value="Right">Right</option>
                    </x-select>
                </div>
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Result") }}</label>
                    <x-select wire:model.live="result" class="w-full lg:w-32">
                        <option value="">{{ __("All") }}</option>
                        <option value="std">Standard</option>
                        <option value="fail">Not Standard</option>
                    </x-select>
                </div>
            </div>
        </div>
    </div>

    <!-- Option 1: Overview Cards (KPIs) -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- Total Tests -->
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-neutral-600 dark:text-neutral-400 uppercase">{{ __("Total Tests") }}</p>
                    <p class="mt-2 text-3xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format($overview['total_tests']) }}</p>
                </div>
                <div class="p-3 bg-blue-100 dark:bg-blue-900/30 rounded-full">
                    <i class="icon-clipboard-check text-2xl text-blue-600 dark:text-blue-400"></i>
                </div>
            </div>
        </div>

        <!-- Pass Rate -->
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-neutral-600 dark:text-neutral-400 uppercase">{{ __("Standard Rate") }}</p>
                    <p class="mt-2 text-3xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $overview['pass_rate'] }}%</p>
                    <p class="text-xs text-neutral-500 mt-1">{{ $overview['passed_tests'] }} / {{ $overview['total_tests'] }}</p>
                </div>
                <div class="p-3 bg-green-100 dark:bg-green-900/30 rounded-full">
                    <i class="icon-check-circle text-2xl text-green-600 dark:text-green-400"></i>
                </div>
            </div>
        </div>

        <!-- Average Peak Pressure -->
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-neutral-600 dark:text-neutral-400 uppercase">{{ __("Avg Peak Pressure") }}</p>
                    <p class="mt-2 text-3xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $overview['avg_peak_pressure'] }}</p>
                    <p class="text-xs text-neutral-500 mt-1">kPa</p>
                </div>
                <div class="p-3 bg-purple-100 dark:bg-purple-900/30 rounded-full">
                    <i class="icon-activity text-2xl text-purple-600 dark:text-purple-400"></i>
                </div>
            </div>
        </div>

        <!-- Active Machines -->
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-neutral-600 dark:text-neutral-400 uppercase">{{ __("Active Machines") }}</p>
                    <p class="mt-2 text-3xl font-semibold text-neutral-900 dark:text-neutral-100">{{ $overview['active_machines'] }}</p>
                </div>
                <div class="p-3 bg-orange-100 dark:bg-orange-900/30 rounded-full">
                    <i class="icon-cpu text-2xl text-orange-600 dark:text-orange-400"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Table -->
    <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6 mb-6">
        <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100 mb-4">{{ __("Load Cell Test Summary") }}</h2>
        
        @if(count($summaryTable) > 0)
            @php
                // Group by plant for better organization
                $groupedByPlant = [];
                foreach ($summaryTable as $row) {
                    $plantKey = $row['plant'];
                    if (!isset($groupedByPlant[$plantKey])) {
                        $groupedByPlant[$plantKey] = [];
                    }
                    $groupedByPlant[$plantKey][] = $row;
                }
            @endphp

            @foreach($groupedByPlant as $plant => $rows)
                <div class="mb-8">
                    <div class="overflow-x-auto overflow-y-auto max-h-[600px] rounded-lg border border-neutral-200 dark:border-neutral-700">
                        <table class="table-auto min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
                            <thead class="bg-neutral-50 dark:bg-neutral-900 sticky top-0 z-10">
                                {{-- Row 1: Plant, Line, Timestamp, Machine Headers --}}
                                <tr class="bg-neutral-50 dark:bg-neutral-900">
                                    <th rowspan="2" class="sticky left-0 z-20 bg-neutral-50 dark:bg-neutral-900 px-5 py-4 text-left text-base font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider border-r border-neutral-200 dark:border-neutral-700">
                                        {{ __("Plant") }}
                                    </th>
                                    <th rowspan="2" class="sticky left-[100px] z-20 bg-neutral-50 dark:bg-neutral-900 px-5 py-4 text-left text-base font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider border-r border-neutral-200 dark:border-neutral-700">
                                        {{ __("Line") }}
                                    </th>
                                    <th rowspan="2" class="sticky left-[180px] z-20 bg-neutral-50 dark:bg-neutral-900 px-5 py-4 text-left text-base font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider border-r border-neutral-200 dark:border-neutral-700">
                                        {{ __("Timestamp") }}
                                    </th>
                                    @foreach(['1', '2', '3', '4'] as $mc)
                                        <th  colspan="6" class="min-w-[400px] px-4 py-4 text-center text-base font-semibold text-neutral-500 dark:text-neutral-400 uppercase tracking-wider {{ $mc !== '4' ? 'border-r border-neutral-200 dark:border-neutral-700' : '' }}">
                                            {{ __("Machine") }} {{ $mc }}
                                        </th>
                                    @endforeach
                                </tr>
                                {{-- Row 2: Left/Right sub-headers --}}
                                <tr class="bg-neutral-100 dark:bg-neutral-800">
                                    @foreach(['1', '2', '3', '4'] as $mc)
                                        <th colspan="3" class="px-4 py-4 text-center text-base font-semibold text-neutral-500 dark:text-neutral-400 uppercase border-r border-neutral-200 dark:border-neutral-700 bg-neutral-100 dark:bg-neutral-800">
                                            {{ __("Left") }}
                                        </th>
                                        <th colspan="3" class="px-4 py-4 text-center text-base font-semibold text-neutral-500 dark:text-neutral-400 uppercase {{ $mc !== '4' ? 'border-r border-neutral-200 dark:border-neutral-700' : '' }} bg-neutral-100 dark:bg-neutral-800">
                                            {{ __("Right") }}
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-neutral-800">
                                @foreach($rows as $rowIndex => $row)
                                    @php
                                        $rowCount = 6; // Total rows per plant-line: toe, m1-c1-l1, m2-c2-l2, heel
                                        $machines = ['1', '2', '3', '4'];
                                        $positions = ['L', 'R'];
                                    @endphp
                                    
                                    {{-- Row 1: Toe values --}}
                                    <tr class="border-t-2 border-neutral-300 dark:border-neutral-600">
                                        <td rowspan="4" class="sticky left-0 z-10 bg-white dark:bg-neutral-800 px-5 py-6 whitespace-nowrap text-lg font-semibold text-neutral-900 dark:text-neutral-100 border-r border-neutral-200 dark:border-neutral-700 align-middle min-w-[100px]">
                                            {{ $row['plant'] }}
                                        </td>
                                        <td rowspan="4" class="sticky left-[100px] z-10 bg-white dark:bg-neutral-800 px-5 py-6 whitespace-nowrap text-lg font-semibold text-neutral-900 dark:text-neutral-100 border-r border-neutral-200 dark:border-neutral-700 align-middle min-w-[80px]">
                                            {{ $row['line'] }}
                                        </td>
                                        <td rowspan="4" class="sticky left-[180px] z-10 bg-white dark:bg-neutral-800 px-5 py-6 whitespace-nowrap text-base text-neutral-600 dark:text-neutral-400 font-mono border-r border-neutral-200 dark:border-neutral-700 align-middle min-w-[120px]">
                                            {{ \Carbon\Carbon::parse($row['latest_timestamp'])->format('m-d-Y') }}
                                        </td>
                                        @foreach($machines as $mc)
                                            @foreach($positions as $pos)
                                                @php
                                                    $data = $row['machines'][$mc][$pos] ?? [];
                                                    $toeValue = $data['toe'] ?? null;
                                                    $result = $data['result'] ?? null;
                                                    $bgClass = '';
                                                    if ($result === 'NG') {
                                                        $bgClass = 'bg-yellow-100 dark:bg-yellow-900/30';
                                                    }
                                                @endphp
                                                <td colspan="3" class="px-4 py-5 text-center text-sm text-neutral-700 dark:text-neutral-300 {{ $bgClass }} {{ ($mc !== '4' || $pos !== 'R') ? 'border-r border-neutral-200 dark:border-neutral-700' : '' }}">
                                                    <div class="text-xs text-neutral-500 dark:text-neutral-400 mb-1">TOE</div>
                                                    <div class="font-bold text-xl">{{ $toeValue ?? '—' }}</div>
                                                </td>
                                            @endforeach
                                        @endforeach
                                    </tr>

                                    {{-- Row 2: m1, c1, l1 values --}}
                                    <tr>
                                        @foreach($machines as $mc)
                                            @foreach($positions as $pos)
                                                @php
                                                    $data = $row['machines'][$mc][$pos] ?? [];
                                                    $m1 = $data['m1'] ?? null;
                                                    $c1 = $data['c1'] ?? null;
                                                    $l1 = $data['l1'] ?? null;
                                                    $result = $data['result'] ?? null;
                                                    $bgClass = '';
                                                    if ($result === 'NG') {
                                                        $bgClass = 'bg-yellow-100 dark:bg-yellow-900/30';
                                                    }
                                                @endphp
                                                <td class="px-4 py-4 text-center text-sm text-neutral-700 dark:text-neutral-300 {{ $bgClass }} border-r border-neutral-100 dark:border-neutral-700 min-w-[70px]">
                                                    <div class="text-xs text-neutral-500 dark:text-neutral-400 mb-1">M1</div>
                                                    <div class="font-bold text-xl">{{ $m1 ?? '—' }}</div>
                                                </td>
                                                <td class="px-4 py-4 text-center text-sm text-neutral-700 dark:text-neutral-300 {{ $bgClass }} border-r border-neutral-100 dark:border-neutral-700 min-w-[70px]">
                                                    <div class="text-xs text-neutral-500 dark:text-neutral-400 mb-1">C1</div>
                                                    <div class="font-bold text-xl">{{ $c1 ?? '—' }}</div>
                                                </td>
                                                <td class="px-4 py-4 text-center text-sm text-neutral-700 dark:text-neutral-300 {{ $bgClass }} {{ ($mc !== '4' || $pos !== 'R') ? 'border-r border-neutral-200 dark:border-neutral-700' : '' }} min-w-[70px]">
                                                    <div class="text-xs text-neutral-500 dark:text-neutral-400 mb-1">L1</div>
                                                    <div class="font-bold text-xl">{{ $l1 ?? '—' }}</div>
                                                </td>
                                            @endforeach
                                        @endforeach
                                    </tr>

                                    {{-- Row 3: m2, c2, l2 values --}}
                                    <tr>
                                        @foreach($machines as $mc)
                                            @foreach($positions as $pos)
                                                @php
                                                    $data = $row['machines'][$mc][$pos] ?? [];
                                                    $m2 = $data['m2'] ?? null;
                                                    $c2 = $data['c2'] ?? null;
                                                    $l2 = $data['l2'] ?? null;
                                                    $result = $data['result'] ?? null;
                                                    $bgClass = '';
                                                    if ($result === 'NG') {
                                                        $bgClass = 'bg-yellow-100 dark:bg-yellow-900/30';
                                                    }
                                                @endphp
                                                <td class="px-4 py-4 text-center text-sm text-neutral-700 dark:text-neutral-300 {{ $bgClass }} border-r border-neutral-100 dark:border-neutral-700 min-w-[70px]">
                                                    <div class="text-xs text-neutral-500 dark:text-neutral-400 mb-1">M2</div>
                                                    <div class="font-bold text-xl">{{ $m2 ?? '—' }}</div>
                                                </td>
                                                <td class="px-4 py-4 text-center text-sm text-neutral-700 dark:text-neutral-300 {{ $bgClass }} border-r border-neutral-100 dark:border-neutral-700 min-w-[70px]">
                                                    <div class="text-xs text-neutral-500 dark:text-neutral-400 mb-1">C2</div>
                                                    <div class="font-bold text-xl">{{ $c2 ?? '—' }}</div>
                                                </td>
                                                <td class="px-4 py-4 text-center text-sm text-neutral-700 dark:text-neutral-300 {{ $bgClass }} {{ ($mc !== '4' || $pos !== 'R') ? 'border-r border-neutral-200 dark:border-neutral-700' : '' }} min-w-[70px]">
                                                    <div class="text-xs text-neutral-500 dark:text-neutral-400 mb-1">L2</div>
                                                    <div class="font-bold text-xl">{{ $l2 ?? '—' }}</div>
                                                </td>
                                            @endforeach
                                        @endforeach
                                    </tr>

                                    {{-- Row 4: Heel values --}}
                                    <tr class="border-b border-neutral-200 dark:border-neutral-700">
                                        @foreach($machines as $mc)
                                            @foreach($positions as $pos)
                                                @php
                                                    $data = $row['machines'][$mc][$pos] ?? [];
                                                    $heelValue = $data['heel'] ?? null;
                                                    $result = $data['result'] ?? null;
                                                    $bgClass = '';
                                                    if ($result === 'NG') {
                                                        $bgClass = 'bg-yellow-100 dark:bg-yellow-900/30';
                                                    }
                                                @endphp
                                                <td colspan="3" class="px-4 py-5 text-center text-sm text-neutral-700 dark:text-neutral-300 {{ $bgClass }} {{ ($mc !== '4' || $pos !== 'R') ? 'border-r border-neutral-200 dark:border-neutral-700' : '' }}">
                                                    <div class="text-xs text-neutral-500 dark:text-neutral-400 mb-1">Heel</div>
                                                    <div class="font-bold text-xl">{{ $heelValue ?? '—' }}</div>
                                                </td>
                                            @endforeach
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        @else
            <div class="text-center py-8 text-neutral-500 dark:text-neutral-400">
                {{ __("No data available for the selected filters") }}
            </div>
        @endif
    </div>
</div>