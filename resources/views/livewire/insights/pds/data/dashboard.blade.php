<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use App\Models\InsPhDosingCount;
use App\Models\InsPhDosingDevice;
use App\Traits\HasDateRangeFilter;
use App\Services\GetDataViaModbus;
use App\Services\UptimeCalculatorService;
use App\Services\DurationFormatterService;
use App\Services\WorkingHoursService;
use App\Models\Project;
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

    public $ip_address = "";

    #[Url]
    public string $machine = "";

    #[Url]
    public string $condition = "all";

    public $stdMinPh = 2;
    public $stdMaxPh = 3;

    public int $perPage = 20;
    public $view = "raw";
    public $onlineStats = [];
    
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
        $this->loadOnlineStats();
        $this->stdMinPh = $this->getStdMinPh();
        $this->stdMaxPh = $this->getStdMaxPh();
    }

    public function updatedStartAt()
    {
        $this->loadOnlineStats();
        $this->resetPage();
    }
    
    public function updatedEndAt()
    {
        $this->loadOnlineStats();
        $this->resetPage();
    }
    
    public function updatedPlant()
    {
        $this->stdMinPh = $this->getStdMinPh();
        $this->stdMaxPh = $this->getStdMaxPh();
        $this->loadOnlineStats();
        $this->resetPage();
    }

    #[On('update')]
    public function refreshCharts(): void
    {
        $this->loadOnlineStats();
        $this->dispatch('chart-data-updated', chartData: $this->getChartData());
        $this->dispatch('refresh-online-chart', onlineData: $this->prepareOnlineChartData());
        $this->dispatch('refresh-status-chart', statusData: $this->prepareStatusChartData());
    }
    
    public function statsByStatus()
    {
        return $this->getStatsByStatus();
    }
    
    private function loadOnlineStats(): void
    {
        $device = $this->getActiveDevice();
        
        if (!$device) {
            $this->onlineStats = $this->getEmptyOnlineStats();
            return;
        }
        
        $projectName = $this->getProjectNameByIp($device->ip_address);
        $project = Project::where('ip', $device->ip_address)->first();
        if (!$project) {
            $this->onlineStats = $this->getEmptyOnlineStats();
            return;
        }
        
        $date = Carbon::parse($this->start_at);
        
        // Use WorkingHoursService to get working hours
        $workingHoursService = app(WorkingHoursService::class);
        $workingHours = $workingHoursService->getProjectWorkingHours($project->id);
        // Get the first working hour configuration or use default from config
        if (!empty($workingHours)) {
            $firstShift = $workingHours[0];
            $startTime = Carbon::parse($firstShift['start_time']);
            $endTime = Carbon::parse($firstShift['end_time']);
            $start = $date->copy()->setTime($startTime->hour, $startTime->minute);
            $end = $date->copy()->setTime($endTime->hour, $endTime->minute);
        } else {
            // Fallback to config if no working hours configured
            $configHours = ['start' => 5.30, 'end' => 15.00];
            $start = $date->copy()->setTime($configHours['start'], 0);
            $end = $date->copy()->setTime($configHours['end'], 0);
        }
        
        $calculator = app(UptimeCalculatorService::class);
        $stats = $calculator->calculateStats($project->name, $start, $end);
        
        $formatter = app(DurationFormatterService::class);
        
        $this->onlineStats = [
            'online_percentage' => $stats['online_percentage'],
            'offline_percentage' => $stats['offline_percentage'],
            'timeout_percentage' => $stats['timeout_percentage'],
            'online_time' => $formatter->format($stats['online_duration']),
            'offline_time' => $formatter->format($stats['offline_duration']),
            'timeout_time' => $formatter->format($stats['timeout_duration']),
        ];
    }
    
    private function getActiveDevice(): ?InsPhDosingDevice
    {
        return InsPhDosingDevice::where('is_active', true)
            ->where('id', $this->plant)
            ->first();
    }
    
    private function getProjectNameByIp(string $ipAddress): ?string
    {
        $project = Project::where('ip', $ipAddress)->first();
        return $project ? $project->name : null;
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

    private function getStatsByStatus() {
        try {
            $start = Carbon::parse($this->start_at);
            $end   = Carbon::parse($this->end_at);
            
            // Calculate number of days BEFORE modifying start/end with startOfDay/endOfDay
            $numberOfDays = $start->diffInDays($end) + 1; // +1 to include both start and end dates
            
            $query = InsPhDosingCount::whereBetween("created_at", [$start->startOfDay(), $end->endOfDay()]);
            
            // Filter by plant if selected
            if ($this->plant) {
                $query->whereHas('device', function($q) {
                    $q->where('id', $this->plant);
                });
            }
            
            $data = $query->orderBy("created_at", "ASC")->get();
            
            // Return empty stats if no data
            if ($data->isEmpty()) {
                return $this->getEmptyStatusStats();
            }
            
            $normalCount = 0;
            $highCount = 0;
            $lowCount = 0;
            
            foreach ($data as $count) {
                // Safely extract pH value
                if (is_array($count->ph_value)) {
                    $phValue = $count->ph_value['current_ph'] ?? $count->ph_value['current'] ?? null;
                } else {
                    $phValue = null;
                }
                
                // Skip invalid pH values
                if ($phValue === null || !is_numeric($phValue)) {
                    continue;
                }
                
                $phValue = (float) $phValue;
                
                if ($phValue >= $this->stdMinPh && $phValue <= $this->stdMaxPh) {
                    $normalCount++;
                } elseif ($phValue > $this->stdMaxPh) {
                    $highCount++;
                } else {
                    $lowCount++;
                }
            }
            
            // Get working hours from WorkingHoursService
            $device = $this->getActiveDevice();
            $project = $device ? Project::where('ip', $device->ip_address)->first() : null;
            
            if ($project) {
                $workingHoursService = app(WorkingHoursService::class);
                $projectWorkingHours = $workingHoursService->getProjectWorkingHours($project->id);
                
                if (!empty($projectWorkingHours)) {
                    $firstShift = $projectWorkingHours[0];
                    $startTime = Carbon::parse($firstShift['start_time']);
                    $endTime = Carbon::parse($firstShift['end_time']);
                    
                    // Handle night shifts (end time before start time)
                    if ($endTime->lessThan($startTime)) {
                        $endTime->addDay();
                    }
                    
                    // Calculate total working minutes using diffInMinutes for precision
                    $totalWorkingMinutesPerDay = $startTime->diffInMinutes($endTime);
                    
                    // Calculate break time from break_times array
                    $breakMinutes = 0;
                    if (!empty($firstShift['break_times'])) {
                        foreach ($firstShift['break_times'] as $breakTime) {
                            $breakStart = Carbon::parse($breakTime['start']);
                            $breakEnd = Carbon::parse($breakTime['end']);
                            
                            // Handle breaks crossing midnight
                            if ($breakEnd->lessThan($breakStart)) {
                                $breakEnd->addDay();
                            }
                            
                            $breakMinutes += $breakStart->diffInMinutes($breakEnd);
                        }
                    }
                    
                    // Subtract break time and ensure non-negative
                    $totalWorkingMinutesPerDay = max(0, $totalWorkingMinutesPerDay - $breakMinutes);
                    
                    // Multiply by number of days in the date range
                    $totalWorkingMinutes = $totalWorkingMinutesPerDay * $numberOfDays;
                    $totalWorkingHours = $totalWorkingMinutes / 60;
                } else {
                    // Fallback to default if no working hours configured
                    $totalWorkingHours = 8 * $numberOfDays;
                    $totalWorkingMinutes = 480 * $numberOfDays;
                }
            } else {
                // Fallback to default if no project found
                $totalWorkingHours = 8 * $numberOfDays;
                $totalWorkingMinutes = 480 * $numberOfDays;
            }
            
            // Calculate total minutes for each status (each count represents 5 minutes)
            $normalMinutes = $normalCount * 5;
            $highMinutes = $highCount * 5;
            $lowMinutes = $lowCount * 5;
            
            // Avoid division by zero
            $normalPercentage = $totalWorkingMinutes > 0 ? ($normalMinutes / $totalWorkingMinutes) * 100 : 0;
            $highPercentage = $totalWorkingMinutes > 0 ? ($highMinutes / $totalWorkingMinutes) * 100 : 0;
            $lowPercentage = $totalWorkingMinutes > 0 ? ($lowMinutes / $totalWorkingMinutes) * 100 : 0;
            
            // Format time as HH:MM:SS
            $normalTime = sprintf('%02d:%02d:%02d', floor($normalMinutes / 60), $normalMinutes % 60, 0);
            $highTime = sprintf('%02d:%02d:%02d', floor($highMinutes / 60), $highMinutes % 60, 0);
            $lowTime = sprintf('%02d:%02d:%02d', floor($lowMinutes / 60), $lowMinutes % 60, 0);
            
            return [
                'normal_count' => $normalCount,
                'normal_minutes' => $normalMinutes,
                'normal_time' => $normalTime,
                'normal_percentage' => round($normalPercentage, 2),
                
                'high_count' => $highCount,
                'high_minutes' => $highMinutes,
                'high_time' => $highTime,
                'high_percentage' => round($highPercentage, 2),
                
                'low_count' => $lowCount,
                'low_minutes' => $lowMinutes,
                'low_time' => $lowTime,
                'low_percentage' => round($lowPercentage, 2),
                
                'total_working_hours' => $totalWorkingHours,
                'total_working_minutes' => $totalWorkingMinutes,
                'number_of_days' => $numberOfDays,
            ];
        } catch (\Exception $e) {
            return $this->getEmptyStatusStats();
        }
    }
    
    private function getEmptyStatusStats(): array
    {
        return [
            'normal_count' => 0,
            'normal_minutes' => 0,
            'normal_time' => '00:00:00',
            'normal_percentage' => 0,
            
            'high_count' => 0,
            'high_minutes' => 0,
            'high_time' => '00:00:00',
            'high_percentage' => 0,
            
            'low_count' => 0,
            'low_minutes' => 0,
            'low_time' => '00:00:00',
            'low_percentage' => 0,
            
            'total_working_hours' => 8,
            'number_of_days' => 1,
            'total_working_minutes' => 480,
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

    private function prepareStatusChartData(): array
    {
        $stats = $this->statsByStatus();
        return [
            'labels' => ['Normal pH', 'High pH', 'Low pH'],
            'datasets' => [[
                'data' => [$stats['normal_percentage'] ?? 0, $stats['high_percentage'] ?? 0, $stats['low_percentage'] ?? 0],
                'backgroundColor' => [
                    'rgba(34, 197, 94, 0.8)',   // green for Normal pH
                    'rgba(239, 68, 68, 0.8)',    // red for High pH
                    'rgba(234, 179, 8, 0.8)',    // yellow for Low pH
                ],
                'borderColor' => [
                    'rgba(34, 197, 94, 1)',      // green
                    'rgba(239, 68, 68, 1)',      // red
                    'rgba(234, 179, 8, 1)',      // yellow
                ],
                'borderWidth' => 1,
            ]]
        ];
    }

    // get working hour in the day
    public function getWorkingHourInTheDay(?Carbon $date = null, ?Project $project = null)
    {
        // Use current date if not provided
        $date = $date ?? Carbon::parse($this->start_at);
        
        // Get project if not provided
        if (!$project) {
            $device = $this->getActiveDevice();
            if (!$device) {
                return '0 seconds';
            }
            $project = Project::where('ip', $device->ip_address)->first();
            if (!$project) {
                return '0 seconds';
            }
        }
        
        // Use WorkingHoursService to get working hours
        $workingHoursService = app(WorkingHoursService::class);
        $workingHours = $workingHoursService->getProjectWorkingHours($project->id);
        
        // Get the first working hour configuration or use default from config
        if (!empty($workingHours)) {
            $firstShift = $workingHours[0];
            $startTime = Carbon::parse($firstShift['start_time']);
            $endTime = Carbon::parse($firstShift['end_time']);
            $start = $date->copy()->setTime($startTime->hour, $startTime->minute);
            $end = $date->copy()->setTime($endTime->hour, $endTime->minute);
        } else {
            // Fallback to config if no working hours configured
            $configHours = config('bpm.working_hours', ['start' => 7, 'end' => 19]);
            $start = $date->copy()->setTime($configHours['start'], 0);
            $end = $date->copy()->setTime($configHours['end'], 0);
        }
        
        $calculator = app(UptimeCalculatorService::class);
        $stats = $calculator->calculateStats($project->name, $start, $end);
        
        $formatter = app(DurationFormatterService::class);
        return $formatter->format($stats['online_duration']);
    }

    public function getOneHourAgoPh(GetDataViaModbus $service)
    {
        try {
            $device = InsPhDosingDevice::where("id", $this->plant)->first();
            if (!$device || !$device->ip_address) {
                return "-";
            }
            $this->ip_address = $device->ip_address;
            $data = $service->getDataReadInputRegisters($this->ip_address, 503, 1, 10, '1_hours_ago_ph');
            if (!$data || $data == 0) {
                return "-";
            }
            return (float) $data / 100;
        } catch (\Exception $e) {
            return "-";
        }
    }

    public function getCurrentPh(GetDataViaModbus $service)
    {
        try {
            $device = InsPhDosingDevice::where("id", $this->plant)->first();
            if (!$device || !$device->ip_address) {
                return "-";
            }
            $this->ip_address = $device->ip_address;
            $data = $service->getDataReadInputRegisters($this->ip_address, 503, 1, 0, 'current_ph');
            if (!$data || $data == 0) {
                return "-";
            }
            return (float) $data / 100;
        } catch (\Exception $e) {
            return "-";
        }
    }
    
    function getUniquePlants()
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
            $dosingLogs = \App\Models\InsPhDosingLog::with('device')
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

            // Group data by hourly intervals
            $hourlyData = [];
            
            foreach ($data as $count) {
                $timestamp = Carbon::parse($count->created_at);
                
                // Create hourly key (format: Y-m-d H:00:00)
                $hourKey = $timestamp->format('Y-m-d H:00:00');
                
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
                
                // Initialize hour group if not exists
                if (!isset($hourlyData[$hourKey])) {
                    $hourlyData[$hourKey] = [
                        'sum' => 0,
                        'count' => 0,
                        'timestamp' => $timestamp->startOfHour(),
                        'has_dosing' => false
                    ];
                }
                
                // Accumulate pH values
                $hourlyData[$hourKey]['sum'] += (float) $phValue;
                $hourlyData[$hourKey]['count']++;
            }

            // Process dosing events and mark intervals
            foreach ($dosingLogs as $log) {
                $timestamp = Carbon::parse($log->created_at);
                
                // Create hourly key
                $hourKey = $timestamp->format('Y-m-d H:00:00');
                
                // Mark this interval as having dosing event
                if (isset($hourlyData[$hourKey])) {
                    $hourlyData[$hourKey]['has_dosing'] = true;
                }
            }

            // Return empty chart if no valid data after processing
            if (empty($hourlyData)) {
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

            // Sort by hour key and build final arrays
            ksort($hourlyData);
            
            foreach ($hourlyData as $hourKey => $hourInfo) {
                $avgPh = $hourInfo['count'] > 0 ? $hourInfo['sum'] / $hourInfo['count'] : 0;
                
                // Format label based on date range
                if ($showDate) {
                    $chartData['labels'][] = $hourInfo['timestamp']->format('d/m H:00');
                } else {
                    $chartData['labels'][] = $hourInfo['timestamp']->format('H:00');
                }
                
                $chartData['phValues'][] = round($avgPh, 2);
                
                // Add dosing marker if this interval has dosing event
                if ($hourInfo['has_dosing']) {
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

    public function getTPMCodeMachine()
    {
        try {
            $device = InsPhDosingDevice::where("id", $this->plant)->first();
            if (!$device || !$device->config) {
                return "-";
            }
            $dataJson = $device->config;
        } catch (\Exception $e) {
           return "-";
        }
        return $dataJson['tpm_code'] ?? "-";
    }

    public function getStdMinPh()
    {
        try {
            $device = InsPhDosingDevice::where("id", $this->plant)->first();
            if (!$device || !$device->config) {
                return 2;
            }
            $dataJson = $device->config;
        } catch (\Exception $e) {
            return 2;
        }
        return $dataJson['standard_ph']['min'] ?? 2;
    }

    public function getStdMaxPh()
    {
        try {
            $device = InsPhDosingDevice::where("id", $this->plant)->first();
            if (!$device || !$device->config) {
                return 3;
            }
            $dataJson = $device->config;
        } catch (\Exception $e) {
            return 3;
        }
        return $dataJson['standard_ph']['max'] ?? 3;
    }

    public function with(): array
    {
        try {
            return [
                'counts' => $this->getCountsQuery()->paginate($this->perPage),
                'chartData' => $this->getChartData(),
            ];
        } catch (\Exception $e) {
            return [
                'counts' => collect(),
                'chartData' => $this->getEmptyChartData(),
            ];
        }
    }

}; ?>

<div wire:poll.20s="refreshCharts">
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
                        @foreach($this->getUniquePlants() as $id => $plantOption)
                            <option value="{{$id}}">{{$plantOption}}</option>
                        @endforeach
                    </x-select>
                </div>
            </div>
            <div class="border-l border-neutral-300 dark:border-neutral-700 mx-2"></div>
        </div>

        <!-- Second Row: TPM Code and Latest Calibration -->
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-2 mt-3" wire:key="stats-{{ $start_at }}-{{ $end_at }}-{{ $plant }}">
            <!-- TPM Code Machine -->
            <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-2 text-center">
                <p class="text-lg font-semibold text-neutral-600 dark:text-neutral-400 mb-3">{{ __("TPM Code Machine") }}</p>
                <h2 class="text-2xl font-bold text-neutral-800 dark:text-neutral-200">{{ $this->getTPMCodeMachine() }}</h2>
            </div>

            <!-- Latest Calibration -->
            <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-2 text-center">
                <p class="text-lg font-semibold text-neutral-600 dark:text-neutral-400 mb-3">{{ __("Latest Calibration") }}</p>
                <h2 class="text-2xl font-bold text-neutral-800 dark:text-neutral-200">{{ now()->format("Y-m-d H:i:s") }}</h2>
            </div>
            <!-- Current PH -->
            <div class="shadow sm:rounded-lg p-2 text-center @if($this->getCurrentPh( new GetDataViaModbus() ) > 3) bg-red-500 dark:bg-red-700 @elseif($this->getCurrentPh( new GetDataViaModbus() ) < 2) bg-yellow-500 dark:bg-yellow-700 @else bg-green-500 dark:bg-green-700 @endif rounded-lg">
                <p class="text-lg font-semibold @if($this->getCurrentPh( new GetDataViaModbus() ) > 3) text-white @elseif($this->getCurrentPh( new GetDataViaModbus() ) < 2) text-white @else text-neutral-800 dark:text-neutral-200 @endif mb-3">{{ __("Current pH") }}</p>
                <h2 class="text-5xl font-bold @if($this->getCurrentPh( new GetDataViaModbus() ) > 3) text-white @elseif($this->getCurrentPh( new GetDataViaModbus() ) < 2) text-white @else text-neutral-800 dark:text-neutral-200 @endif">{{ $this->getCurrentPh( new GetDataViaModbus() ) }}</h2>
            </div>

            <!-- 1 Hour Ago PH -->
            <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-2 text-center">
                <p class="text-lg font-semibold text-neutral-600 dark:text-neutral-400 mb-3">{{ __("1 hour ago pH") }}</p>
                <h2 class="text-5xl font-bold text-neutral-800 dark:text-neutral-200">{{ $this->getOneHourAgoPh( new GetDataViaModbus() ) }}</h2>
            </div>
        </div>

        <!-- Top Row: Three Cards -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mt-2">
            <!-- Online System Monitoring -->
            <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6" wire:key="online-{{ $start_at }}-{{ $end_at }}-{{ $plant }}">
                <h3 class="text-lg text-center font-semibold text-neutral-700 dark:text-neutral-300 mb-4">{{ __("Online System Monitoring") }}</h3>
                <div class="grid grid-cols-2 gap-2">
                    <div class="col-span-1 h-[150px] items-center justify-center">
                        <div class="flex items-center justify-center h-full">
                            <canvas id="onlineChart" wire:ignore></canvas>
                        </div>
                    </div>
                    
                    <!-- Legend -->
                    <div class="col-span-1 flex flex-col gap-y-1 text-left" wire:key="online-legend-{{ $start_at }}-{{ $end_at }}-{{ $plant }}">
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded-full bg-green-500"></div>
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ __("Online") }}</span>
                            <span class="ml-auto text-sm font-semibold text-green-600 dark:text-green-400">{{ $onlineStats['online_percentage'] ?? 0 }}%</span>
                        </div>
                        <div class="text-sm text-gray-500 ml-5">{{ $onlineStats['online_time'] ?? '0 seconds' }}</div>
                        
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded-full bg-gray-500"></div>
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ __("Offline") }}</span>
                            <span class="ml-auto text-sm font-semibold text-gray-600 dark:text-gray-400">{{ $onlineStats['offline_percentage'] ?? 0 }}%</span>
                        </div>
                        <div class="text-sm text-gray-500 ml-5">{{ $onlineStats['offline_time'] ?? '0 seconds' }}</div>
                        
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded-full bg-orange-500"></div>
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ __("Timeout") }}</span>
                            <span class="ml-auto text-sm font-semibold text-orange-600 dark:text-orange-400">{{ $onlineStats['timeout_percentage'] ?? 0 }}%</span>
                        </div>
                        <div class="text-sm text-gray-500 ml-5">{{ $onlineStats['timeout_time'] ?? '0 seconds' }}</div>
                    </div>
                </div>
            </div>

            <!-- Total Duration per Status -->
            <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6" wire:key="status-{{ $start_at }}-{{ $end_at }}-{{ $plant }}">
                <h3 class="text-lg text-center font-semibold text-neutral-700 dark:text-neutral-300 mb-4">{{ __("Total Duration per Status") }}</h3>
                <div class="grid grid-cols-2 gap-2">
                    <div class="h-[150px] items-center justify-center">
                        <div class="flex items-center justify-center h-full">
                            <canvas id="statusChart" wire:ignore></canvas>
                        </div>
                    </div>
                    
                    <!-- Legend -->
                    @php
                        $statusStats = $this->statsByStatus();
                    @endphp
                    <div class="flex flex-col gap-y-1 text-left" wire:key="status-legend-{{ $start_at }}-{{ $end_at }}-{{ $plant }}">
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded-full bg-green-500"></div>
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ __("Normal pH") }}</span>
                            <span class="ml-auto text-sm font-semibold text-green-600 dark:text-green-400">{{ $statusStats['normal_percentage'] ?? 0 }}%</span>
                        </div>
                        <div class="text-sm text-gray-500 ml-5">{{ $statusStats['normal_time'] ?? '00:00:00' }}</div>
                        
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded-full bg-red-500"></div>
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ __("High pH") }}</span>
                            <span class="ml-auto text-sm font-semibold text-red-600 dark:text-red-400">{{ $statusStats['high_percentage'] ?? 0 }}%</span>
                        </div>
                        <div class="text-sm text-gray-500 ml-5">{{ $statusStats['high_time'] ?? '00:00:00' }}</div>
                        
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ __("Low pH") }}</span>
                            <span class="ml-auto text-sm font-semibold text-yellow-600 dark:text-yellow-400">{{ $statusStats['low_percentage'] ?? 0 }}%</span>
                        </div>
                        <div class="text-sm text-gray-500 ml-5">{{ $statusStats['low_time'] ?? '00:00:00' }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Chart Area -->
        <div class="mt-3" wire:key="chart-{{ $start_at }}-{{ $end_at }}-{{ $plant }}">
            <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
                <h3 class="text-lg text-center font-semibold text-neutral-700 dark:text-neutral-300 mb-4">{{ __("Daily Trend Chart pH") ." (1 Hour Interval)" }}</h3>
                    <div 
                        x-data="{ 
                            chartData: @js($chartData),
                            chart: null,
                            stdMaxPh: {{ $stdMaxPh }},
                            stdMinPh: {{ $stdMinPh }},
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
                                    this.chart = null;
                                }
                                
                                const phSeries = data.phValues || [];
                                const labels = data.labels || [];
                                const maxLimit = Array(labels.length).fill(this.stdMaxPh);
                                const minLimit = Array(labels.length).fill(this.stdMinPh);
                                
                                const isDark = document.documentElement.classList.contains('dark');
                                const textColor = isDark ? '#d4d4d4' : '#525252';
                                const gridColor = isDark ? '#404040' : '#e5e7eb';
                                
                                const options = {
                                    series: [
                                        { name: 'Nilai pH', data: phSeries, type: 'line' },
                                        { name: 'Batas Maksimal (pH ' + this.stdMaxPh + ')', data: maxLimit, type: 'line' },
                                        { name: 'Batas Minimal (pH ' + this.stdMinPh + ')', data: minLimit, type: 'line' }
                                    ],
                                    chart: {
                                        height: 400,
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
                                        hover: { size: [7, 0, 0] }
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
                        <div x-ref="chartContainer" style="height: 400px; min-height: 400px;"></div>
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

@script
    <script>
        let onlineChart;
        let statusChart;

        function initOnlineChart(onlineData) {
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
        }

        function initStatusChart(statusData) {
            const statusCtx = document.getElementById('statusChart');
            
            if (statusCtx) {
                const existingStatusChart = Chart.getChart(statusCtx);
                if (existingStatusChart) {
                    existingStatusChart.destroy();
                }
                
                statusChart = new Chart(statusCtx, {
                    type: 'doughnut',
                    data: statusData,
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
        }

        // Listen for refresh events
        Livewire.on('chart-data-updated', (event) => {
            const data = event[0] || event;
            // Relay to Alpine via window CustomEvent
            window.dispatchEvent(new CustomEvent('chart-data-updated', {
                detail: { chartData: data.chartData }
            }));
        });

        Livewire.on('refresh-online-chart', (event) => {
            const data = event[0] || event;
            initOnlineChart(data.onlineData);
        });

        Livewire.on('refresh-status-chart', (event) => {
            const data = event[0] || event;
            initStatusChart(data.statusData);
        });

        // Initial load
        const initializeOnlineChart = () => {
            const onlineData = @json($this->prepareOnlineChartData());
            
            // Wait for DOM to be ready
            if (document.getElementById('onlineChart')) {
                initOnlineChart(onlineData);
            } else {
                // Retry after a short delay if element not found
                setTimeout(initializeOnlineChart, 100);
            }
        };

        const initializeStatusChart = () => {
            const statusData = @json($this->prepareStatusChartData());
            
            // Wait for DOM to be ready
            if (document.getElementById('statusChart')) {
                initStatusChart(statusData);
            } else {
                // Retry after a short delay if element not found
                setTimeout(initializeStatusChart, 100);
            }
        };

        // Run after Livewire component is loaded
        initializeOnlineChart();
        initializeStatusChart();
    </script>
    @endscript
</div>