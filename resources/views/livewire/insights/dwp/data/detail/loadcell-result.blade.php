<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use App\Models\InsDwpLoadcell;
use Carbon\Carbon;

new class extends Component {
    public int $id = 0;
    public array $detail = [];

    // Filter dan properti yang tidak terpakai telah dihapus
    #[Url]
    public string $line = "g5";

    #[Url]
    public string $mechine = "";

    public string $machineName = "";

    #[Url]
    public ?int $device_id = null;

    // Properti posisi disimpan untuk judul, tetapi bukan lagi filter URL
    public string $position = "L";
    
    // Filter untuk cycle
    public string $cycleFilter = "all"; // "all" or cycle number "1", "2", "3"

    public string $operator_name = "Operator";
    
    public function mount()
    {
        // Inisialisasi atau logika mount lainnya jika diperlukan
    }
    
    public function updated($property)
    {
        if ($property === 'cycleFilter') {
            $this->renderLoadcellChartClient();
        }
    }

    #[On("loadcell-detail")]
    public function loadPresureDetail($id)
    {
        $this->id = $id;
        $data = InsDwpLoadcell::find($this->id);
        if ($data) {
            $this->detail = json_decode($data->loadcell_data, true) ?? [];
            $this->machineName = $data->machine_name ?? "";
            $this->position = $data->position ?? "";
            $this->operator_name = $data->operator ?? "Operator";
            
            // Log for debugging
            \Log::info('Loadcell data loaded', [
                'id' => $id,
                'has_metadata' => isset($this->detail['metadata']),
                'has_cycles' => isset($this->detail['metadata']['cycles']),
                'cycle_count' => isset($this->detail['metadata']['cycles']) ? count($this->detail['metadata']['cycles']) : 0
            ]);
            
            // Render chart after loading data
            if (!empty($this->detail['metadata']['cycles'])) {
                $this->renderLoadcellChartClient();
            }
        }
    }

    private function calculateMedian(array $values): array
    {
        $count = count($values);
        if ($count === 0) return [];

        $maxLength = max(array_map('count', $values));
        $result = [];

        for ($i = 0; $i < $maxLength; $i++) {
            $pointValues = [];
            foreach ($values as $sensorData) {
                if (isset($sensorData[$i]) && is_numeric($sensorData[$i])) {
                    $pointValues[] = $sensorData[$i];
                }
            }

            if (count($pointValues) > 0) {
                sort($pointValues);
                $mid = floor(count($pointValues) / 2);
                if (count($pointValues) % 2 === 0) {
                    $result[] = ($pointValues[$mid - 1] + $pointValues[$mid]) / 2;
                } else {
                    $result[] = $pointValues[$mid];
                }
            } else {
                $result[] = 0;
            }
        }

        return $result;
    }

    private function renderLoadcellChartClient()
    {
        $cycles = $this->detail['metadata']['cycles'] ?? [];
        
        if (empty($cycles)) {
            return;
        }

        // Filter cycles based on selection
        if ($this->cycleFilter !== 'all') {
            $cycleNumber = (int) $this->cycleFilter;
            $cycles = array_filter($cycles, function($cycle) use ($cycleNumber) {
                return ($cycle['cycle_number'] ?? 0) === $cycleNumber;
            });
            
            if (empty($cycles)) {
                \Log::warning('No cycle found for cycle number: ' . $cycleNumber);
                return;
            }
        }

        // Merge sensor data from selected cycles
        $allLeftSensors = [];
        $allRightSensors = [];
        
        foreach ($cycles as $cycle) {
            $sensors = $cycle['sensors'] ?? [];
            
            foreach ($sensors as $sensorName => $sensorData) {
                if (strpos($sensorName, '_L') !== false) {
                    if (!isset($allLeftSensors[$sensorName])) {
                        $allLeftSensors[$sensorName] = [];
                    }
                    $allLeftSensors[$sensorName] = array_merge($allLeftSensors[$sensorName], $sensorData);
                } elseif (strpos($sensorName, '_R') !== false) {
                    if (!isset($allRightSensors[$sensorName])) {
                        $allRightSensors[$sensorName] = [];
                    }
                    $allRightSensors[$sensorName] = array_merge($allRightSensors[$sensorName], $sensorData);
                }
            }
        }

        // Filter based on position
        $isLeftPosition = strtolower($this->position) === 'left' || strtolower($this->position) === 'l';
        $isRightPosition = strtolower($this->position) === 'right' || strtolower($this->position) === 'r';
        
        // If position is specified, only use matching sensors
        if ($isLeftPosition) {
            $allRightSensors = []; // Clear right sensors if position is Left
        } elseif ($isRightPosition) {
            $allLeftSensors = []; // Clear left sensors if position is Right
        }

        // Calculate median pressure for each sensor
        $sensorLabels = [];
        $sensorPressures = [];
        
        // Process left sensors
        foreach ($allLeftSensors as $sensorName => $sensorData) {
            if (!empty($sensorData)) {
                // Filter out zero values for better median calculation
                $nonZeroValues = array_filter($sensorData, function($val) {
                    return $val > 0;
                });
                
                if (!empty($nonZeroValues)) {
                    sort($nonZeroValues);
                    $count = count($nonZeroValues);
                    $mid = floor($count / 2);
                    
                    if ($count % 2 === 0) {
                        $median = ($nonZeroValues[$mid - 1] + $nonZeroValues[$mid]) / 2;
                    } else {
                        $median = $nonZeroValues[$mid];
                    }
                    
                    $sensorLabels[] = $sensorName;
                    $sensorPressures[] = round($median, 2);
                }
            }
        }
        
        // Process right sensors
        foreach ($allRightSensors as $sensorName => $sensorData) {
            if (!empty($sensorData)) {
                // Filter out zero values for better median calculation
                $nonZeroValues = array_filter($sensorData, function($val) {
                    return $val > 0;
                });
                
                if (!empty($nonZeroValues)) {
                    sort($nonZeroValues);
                    $count = count($nonZeroValues);
                    $mid = floor($count / 2);
                    
                    if ($count % 2 === 0) {
                        $median = ($nonZeroValues[$mid - 1] + $nonZeroValues[$mid]) / 2;
                    } else {
                        $median = $nonZeroValues[$mid];
                    }
                    
                    $sensorLabels[] = $sensorName;
                    $sensorPressures[] = round($median, 2);
                }
            }
        }

        \Log::info('Chart data prepared', [
            'sensor_count' => count($sensorLabels),
            'sensors' => $sensorLabels,
            'pressures' => $sensorPressures
        ]);
        
        // If no sensor data after processing, don't render chart
        if (empty($sensorLabels)) {
            return;
        }

        // Prepare chart data
        $chartData = [
            'labels' => $sensorLabels,
            'pressures' => $sensorPressures,
        ];

        $chartDataJson = json_encode($chartData);

        $this->js(
            "
            (function(){                
                function initChart() {
                    try {
                        const data = " . $chartDataJson . ";
                        function isDarkModeLocal(){
                            try{ return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches || document.documentElement.classList.contains('dark'); }catch(e){return false}
                        }
                        const theme = {
                            textColor: isDarkModeLocal() ? '#e6edf3' : '#0f172a',
                            gridColor: isDarkModeLocal() ? 'rgba(255,255,255,0.06)' : 'rgba(15,23,42,0.06)'
                        };

                        const canvas = document.getElementById('loadcellChart');
                        
                        if (!canvas) {
                            return;
                        }
                        
                        const ctx = canvas.getContext('2d');
                        if (!ctx) {
                            console.error('[Loadcell Chart] Could not get canvas context');
                            return;
                        }
                        
                        if (typeof Chart === 'undefined') {
                            console.error('[Loadcell Chart] Chart.js is not loaded!');
                            return;
                        }

                        if (window.__loadcellChart instanceof Chart) {
                            try { window.__loadcellChart.destroy(); } catch(e){}
                        }

                        const hasData = data && data.labels && data.labels.length > 0;
                        console.log(data.pressures);
                        window.__loadcellChart = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: hasData ? data.labels : [],
                            datasets: [{
                                label: 'Median Pressure (kg)',
                                data: hasData ? data.pressures : [],
                                backgroundColor: 'rgba(59, 130, 246, 0.7)',
                                borderColor: '#3b82f6',
                                borderWidth: 2,
                                hoverBackgroundColor: 'rgba(59, 130, 246, 0.9)',
                                hoverBorderColor: '#2563eb',
                                borderRadius: 4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                mode: 'index',
                                intersect: false
                            },
                            scales: {
                                x: {
                                    type: 'category',
                                    title: {
                                        display: true,
                                        text: 'Sensor Name',
                                        color: theme.textColor,
                                        font: {
                                            size: 14,
                                        }
                                    },
                                    grid: {
                                        color: theme.gridColor,
                                        drawOnChartArea: true,
                                        drawTicks: true
                                    },
                                    ticks: {
                                        color: theme.textColor,
                                        autoSkip: false,
                                        maxRotation: 45,
                                        minRotation: 45,
                                        font: {
                                            size: 11
                                        }
                                    }
                                },
                                y: {
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Pressure (kg)',
                                        color: theme.textColor,
                                        font: {
                                            size: 14,
                                            weight: 'bold'
                                        }
                                    },
                                    grid: {
                                        color: theme.gridColor,
                                        drawOnChartArea: true,
                                        drawTicks: true
                                    },
                                    ticks: {
                                        color: theme.textColor,
                                        callback: function(value, index, ticks) {
                                            return value.toFixed(1) + ' kg';
                                        }
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'top',
                                    labels: {
                                        color: theme.textColor,
                                        usePointStyle: true,
                                        font: {
                                            size: 12
                                        }
                                    }
                                },
                                tooltip: {
                                    mode: 'index',
                                    intersect: false,
                                    position: 'nearest',
                                    backgroundColor: isDarkModeLocal() ? 'rgba(0, 0, 0, 0.8)' : 'rgba(255, 255, 255, 0.9)',
                                    bodyColor: theme.textColor,
                                    titleColor: theme.textColor,
                                    borderColor: theme.gridColor,
                                    borderWidth: 1,
                                    callbacks: {
                                        label: function(context) {
                                            let label = context.dataset.label || '';
                                            if (label) {
                                                label += ': ';
                                            }
                                            if (context.parsed.y !== null) {
                                                label += context.parsed.y.toFixed(2) + ' kg';
                                            }
                                            return label;
                                        },
                                        title: function(context) {
                                            return 'Sensor: ' + context[0].label;
                                        }
                                    }
                                }
                            }
                        }
                    });

                    } catch (e) {
                        console.error('[Loadcell Chart] injected chart render error', e);
                    }
                }
                
                // Wait for DOM to be ready and Livewire to finish rendering
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initChart);
                } else {
                    // DOM is already ready, but wait a bit for Livewire to render
                    setTimeout(initChart, 100);
                }
            })();
            "
        );
    }
};
?>

<div class="p-4 bg-white dark:bg-gray-900 rounded-lg shadow-md relative">
    <div class="grid grid-cols-2 gap-4 mb-4">
        <div>
            <h1 class="text-xl font-semibold text-gray-800 dark:text-gray-200">
                Loadcell Result: {{ $this->mechine }} ({{ $this->position }})
            </h1>
        </div>
        <div class="flex justify-end items-center gap-2">
            <label for="cycleFilter" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                Cycle:
            </label>
            <select 
                id="cycleFilter" 
                wire:model.live="cycleFilter"
                class="px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg 
                       bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100
                       focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="all">All Cycles (Median)</option>
                @if(!empty($this->detail['metadata']['cycles']))
                    @foreach($this->detail['metadata']['cycles'] as $cycle)
                        <option value="{{ $cycle['cycle_number'] }}">
                            Cycle {{ $cycle['cycle_number'] }}
                        </option>
                    @endforeach
                @endif
            </select>
        </div>
    </div>
    
    @if(!empty($this->detail['metadata']['cycles']))
    <div class="grid grid-cols-2 gap-4">
        <div class="h-80 relative">
            <canvas id="loadcellChart"></canvas>
        </div>
        <!-- detail measurement -->
        <div>
            @if(!empty($this->detail['metadata']['cycles']))
                @php
                    // Get cycles based on filter
                    $cycles = $this->detail['metadata']['cycles'] ?? [];
                    $displayCycles = $cycles;
                    
                    if ($this->cycleFilter !== 'all') {
                        $displayCycles = array_filter($cycles, function($cycle) {
                            return ($cycle['cycle_number'] ?? 0) === (int) $this->cycleFilter;
                        });
                    }
                    
                    // Calculate total cycles
                    $totalCycles = count($cycles);
                    $displayingCycles = count($displayCycles);
                    
                    // Get measurement information from metadata
                    $operatorName = $this->operator_name ?? 'Operator';
                    $lineName = $this->detail['metadata']['line_name'] ?? $this->line ?? '-';
                    $plantName = $this->detail['metadata']['plant_name'] ?? $this->detail['metadata']['plant'] ?? '-';
                    
                    // Get time information
                    $recordedAt = $this->detail['metadata']['recorded_at'] ?? null;
                @endphp
                
                <div class="space-y-4">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200">
                        Measurement Information
                    </h3>
                    
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-4">
                        <dl class="space-y-3">
                            <div class="flex justify-between items-center">
                                <dt class="text-sm font-medium text-gray-600 dark:text-gray-400">
                                    Operator Name:
                                </dt>
                                <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $operatorName }}
                                </dd>
                            </div>
                            
                            <div class="flex justify-between items-center">
                                <dt class="text-sm font-medium text-gray-600 dark:text-gray-400">
                                    Line:
                                </dt>
                                <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ strtoupper($lineName) }}
                                </dd>
                            </div>
                            
                            <div class="flex justify-between items-center">
                                <dt class="text-sm font-medium text-gray-600 dark:text-gray-400">
                                    Machine:
                                </dt>
                                <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $this->machineName }}
                                </dd>
                            </div>
                            
                            <div class="flex justify-between items-center">
                                <dt class="text-sm font-medium text-gray-600 dark:text-gray-400">
                                    Plant:
                                </dt>
                                <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $plantName }}
                                </dd>
                            </div>
                            
                            <div class="flex justify-between items-center border-t border-gray-200 dark:border-gray-700 pt-3">
                                <dt class="text-sm font-medium text-gray-600 dark:text-gray-400">
                                    Total Cycles:
                                </dt>
                                <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $totalCycles }}
                                </dd>
                            </div>
                            
                            @if($this->cycleFilter !== 'all')
                                <div class="flex justify-between items-center">
                                    <dt class="text-sm font-medium text-gray-600 dark:text-gray-400">
                                        Displaying Cycle:
                                    </dt>
                                    <dd class="text-sm font-semibold text-blue-600 dark:text-blue-400">
                                        Cycle {{ $this->cycleFilter }}
                                    </dd>
                                </div>
                            @endif
                        </dl>
                    </div>
                    
                    <!-- Time measurement per cycle -->
                    @if($this->cycleFilter !== 'all' && !empty($displayCycles))
                        @php
                            $currentCycle = array_values($displayCycles)[0];
                            $cycleStartTime = $currentCycle['timestamp'] ?? null;
                            $cycleEndTime = isset($currentCycle['timestamp']) ? Carbon::parse($currentCycle['timestamp'])->addSeconds(14)->toDateTimeString() : null;
                            $cycleDuration = 14; // default duration 14 seconds
                            if ($cycleStartTime && $cycleEndTime) {
                                try {
                                    $start = \Carbon\Carbon::parse($cycleStartTime);
                                    $end = \Carbon\Carbon::parse($cycleEndTime);
                                    $cycleDuration = $start->diffInSeconds($end);
                                } catch (\Exception $e) {
                                    $cycleDuration = null;
                                }
                            }
                        @endphp
                        
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-blue-50 dark:bg-blue-900/20 p-4">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-3">
                                Cycle {{ $this->cycleFilter }} Time Details
                            </h4>
                            <dl class="space-y-2">
                                @if($cycleStartTime)
                                    <div class="flex justify-between items-center">
                                        <dt class="text-sm font-medium text-gray-600 dark:text-gray-400">
                                            Start Time:
                                        </dt>
                                        <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                            {{ \Carbon\Carbon::parse($cycleStartTime)->format('Y-m-d H:i:s') }}
                                        </dd>
                                    </div>
                                @endif
                                
                                @if($cycleEndTime)
                                    <div class="flex justify-between items-center">
                                        <dt class="text-sm font-medium text-gray-600 dark:text-gray-400">
                                            End Time:
                                        </dt>
                                        <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                            {{ \Carbon\Carbon::parse($cycleEndTime)->format('Y-m-d H:i:s') }}
                                        </dd>
                                    </div>
                                @endif
                                
                                @if($cycleDuration !== null)
                                    <div class="flex justify-between items-center">
                                        <dt class="text-sm font-medium text-gray-600 dark:text-gray-400">
                                            Duration:
                                        </dt>
                                        <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                            @if($cycleDuration >= 3600)
                                                {{ floor($cycleDuration / 3600) }}h {{ floor(($cycleDuration % 3600) / 60) }}m {{ $cycleDuration % 60 }}s
                                            @elseif($cycleDuration >= 60)
                                                {{ floor($cycleDuration / 60) }}m {{ $cycleDuration % 60 }}s
                                            @else
                                                {{ $cycleDuration }}s
                                            @endif
                                        </dd>
                                    </div>
                                @endif
                            </dl>
                        </div>
                    @endif
                    
                    @if($recordedAt)
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-green-50 dark:bg-green-900/20 p-4">
                            <div class="flex justify-between items-center">
                                <dt class="text-sm font-medium text-gray-600 dark:text-gray-400">
                                    📅 Recorded At:
                                </dt>
                                <dd class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ \Carbon\Carbon::parse($recordedAt)->format('Y-m-d H:i:s') }}
                                </dd>
                            </div>
                        </div>
                    @endif
                    
                    @if($this->cycleFilter === 'all' && count($displayCycles) > 1)
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-amber-50 dark:bg-amber-900/20 p-4">
                            <p class="text-sm text-gray-700 dark:text-gray-300">
                                <span class="font-semibold">Note:</span> Statistics are calculated across all {{ $totalCycles }} cycles. Select a specific cycle to view individual cycle timestamps.
                            </p>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
    @else
        <div class="h-80 flex items-center justify-center text-gray-500 dark:text-gray-400">
            <p>No data available. Please select a record from the list.</p>
        </div>
    @endif

    <div class="grid grid-cols-1 mt-4 gap-4">
        <!-- table detail min, max, median -->
        <div>
            @if(!empty($this->detail['metadata']['cycles']))
                @php
                    // Get cycles based on filter
                    $cycles = $this->detail['metadata']['cycles'] ?? [];
                    if ($this->cycleFilter !== 'all') {
                        $cycles = array_filter($cycles, function($cycle) {
                            return ($cycle['cycle_number'] ?? 0) === (int) $this->cycleFilter;
                        });
                    }
                    
                    // Calculate statistics for each sensor
                    $sensorStats = [];
                    
                    foreach ($cycles as $cycle) {
                        $sensors = $cycle['sensors'] ?? [];
                        
                        foreach ($sensors as $sensorName => $sensorData) {
                            // Filter by position
                            $isLeftPosition = strtolower($this->position) === 'left' || strtolower($this->position) === 'l';
                            $isRightPosition = strtolower($this->position) === 'right' || strtolower($this->position) === 'r';
                            
                            $shouldInclude = true;
                            if ($isLeftPosition && strpos($sensorName, '_L') === false) {
                                $shouldInclude = false;
                            } elseif ($isRightPosition && strpos($sensorName, '_R') === false) {
                                $shouldInclude = false;
                            }
                            
                            if (!$shouldInclude || empty($sensorData)) continue;
                            
                            // Filter out zero values
                            $nonZeroValues = array_filter($sensorData, function($val) {
                                return $val > 0;
                            });
                            
                            if (empty($nonZeroValues)) continue;
                            
                            if (!isset($sensorStats[$sensorName])) {
                                $sensorStats[$sensorName] = [];
                            }
                            
                            $sensorStats[$sensorName] = array_merge($sensorStats[$sensorName], array_values($nonZeroValues));
                        }
                    }
                    
                    // Calculate min, max, median for each sensor
                    $tableData = [];
                    foreach ($sensorStats as $sensorName => $values) {
                        if (empty($values)) continue;
                        
                        sort($values);
                        $count = count($values);
                        $mid = floor($count / 2);
                        
                        $median = ($count % 2 === 0) 
                            ? ($values[$mid - 1] + $values[$mid]) / 2 
                            : $values[$mid];
                        
                        $tableData[] = [
                            'sensor' => $sensorName,
                            'min' => min($values),
                            'max' => max($values),
                            'median' => $median
                        ];
                    }
                @endphp
                
                @if(!empty($tableData))
                    @php
                        // Sort sensors by name to maintain consistent order
                        usort($tableData, function($a, $b) {
                            return strcmp($a['sensor'], $b['sensor']);
                        });
                        
                        // Get unique sensor names for header
                        $sensorNames = array_map(function($item) {
                            return $item['sensor'];
                        }, $tableData);
                    @endphp
                    
                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-800">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Sensor
                                    </th>
                                    @foreach($sensorNames as $sensorName)
                                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                            {{ $sensorName }}
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                @php
                                    $metrics = ['median' => 'Median','max' => 'Max', 'min' => 'Min'];
                                @endphp
                                
                                @foreach($metrics as $metricKey => $metricLabel)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                            {{ $metricLabel }}
                                        </td>
                                        @foreach($tableData as $data)
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-center text-gray-700 dark:text-gray-300">
                                                {{ number_format($data[$metricKey], 0) }}
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </div>
    </div>
    
    <x-spinner-bg wire:loading.class.remove="hidden" wire:target.except="userq"></x-spinner-bg>
    <x-spinner wire:loading.class.remove="hidden" wire:target.except="userq" class="hidden"></x-spinner>
</div>
