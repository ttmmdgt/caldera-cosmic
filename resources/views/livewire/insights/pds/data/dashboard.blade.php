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
use App\Models\InsPhDosingLog;
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
    public string $lastPhStatus = '';
    
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
        $this->checkOneHourAgoPhToast(true);
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
        try {
            $this->loadOnlineStats();
            
            $this->dispatch('refresh-online-chart', onlineData: $this->prepareOnlineChartData());
            $this->dispatch('refresh-status-chart', statusData: $this->prepareStatusChartData());
            $this->checkOneHourAgoPhToast();
        } catch (\Exception $e) {
            \Log::warning('Error refreshing charts: ' . $e->getMessage());
        }
    }
    
    public function statsByStatus()
    {
        return $this->getStatsByStatus();
    }

    public function checkOneHourAgoPhToast(bool $forceShow = false): void
    {
        try {
            $device = InsPhDosingDevice::where('id', $this->plant)->first();
            if (!$device) {
                return;
            }

            $logsCount = InsPhDosingLog::where('device_id', $device->id)
                ->where('created_at', '>=', Carbon::now()->subHour())
                ->count();

            if ($logsCount <= 5) {
                $this->lastPhStatus = '';
                return;
            }

            $service = app(GetDataViaModbus::class);
            $currentPh = $this->getCurrentPh($service);

            if ($currentPh === '-' || !is_numeric($currentPh)) {
                return;
            }

            $currentPh = (float) $currentPh;
            $isStandard = $currentPh >= $this->stdMinPh && $currentPh <= $this->stdMaxPh;

            if ($isStandard) {
                $this->lastPhStatus = '';
                return;
            }

            if ($currentPh > $this->stdMaxPh) {
                $status = 'high';
                $icon = '<svg class="w-8 h-8 text-red-500" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" clip-rule="evenodd" d="M2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12C22 17.5228 17.5228 22 12 22C6.47715 22 2 17.5228 2 12ZM11.9996 7C12.5519 7 12.9996 7.44772 12.9996 8V12C12.9996 12.5523 12.5519 13 11.9996 13C11.4474 13 10.9996 12.5523 10.9996 12V8C10.9996 7.44772 11.4474 7 11.9996 7ZM12.001 14.99C11.4488 14.9892 11.0004 15.4363 10.9997 15.9886L10.9996 15.9986C10.9989 16.5509 11.446 16.9992 11.9982 17C12.5505 17.0008 12.9989 16.5537 12.9996 16.0014L12.9996 15.9914C13.0004 15.4391 12.5533 14.9908 12.001 14.99Z"/></svg>';
                $bgColor = 'bg-red-200 dark:bg-red-950 border-red-300 dark:border-red-700';
                $titleColor = 'text-red-700 dark:text-red-300';
                $message = __('pH Alert: High pH');
                $description = __('Current pH: ') . '<span class="text-2xl font-black">' . number_format($currentPh, 2) . '</span>'
                    . ' (> ' . $this->stdMaxPh . ')<br>'
                    . '<span class="text-xs opacity-75">' . $logsCount . ' ' . __('dosing logs in the last hour') . '</span>';
            } else {
                $status = 'low';
                $icon = '<svg class="w-8 h-8 text-orange-500" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" clip-rule="evenodd" d="M9.44829 4.46472C10.5836 2.51208 13.4105 2.51168 14.5464 4.46401L21.5988 16.5855C22.7423 18.5509 21.3145 21 19.05 21L4.94967 21C2.68547 21 1.25762 18.5516 2.4004 16.5862L9.44829 4.46472ZM11.9995 8C12.5518 8 12.9995 8.44772 12.9995 9V13C12.9995 13.5523 12.5518 14 11.9995 14C11.4473 14 10.9995 13.5523 10.9995 13V9C10.9995 8.44772 11.4473 8 11.9995 8ZM12.0009 15.99C11.4486 15.9892 11.0003 16.4363 10.9995 16.9886L10.9995 16.9986C10.9987 17.5509 11.4458 17.9992 11.9981 18C12.5504 18.0008 12.9987 17.5537 12.9995 17.0014L12.9995 16.9914C13.0003 16.4391 12.5532 15.9908 12.0009 15.99Z"/></svg>';
                $bgColor = 'bg-orange-50 dark:bg-orange-950 border-orange-300 dark:border-orange-700';
                $titleColor = 'text-orange-700 dark:text-orange-300';
                $message = __('pH Alert: Low pH');
                $description = __('Current pH: ') . '<span class="text-2xl font-black">' . number_format($currentPh, 2) . '</span>'
                    . ' (< ' . $this->stdMinPh . ')<br>'
                    . '<span class="text-xs opacity-75">' . $logsCount . ' ' . __('dosing logs in the last hour') . '</span>';
            }

            if ($forceShow || $this->lastPhStatus !== $status) {
                $this->lastPhStatus = $status;

                $html = '<div class="p-5 ' . $bgColor . ' border rounded-lg" style="min-width: 340px;">'
                    . '<div class="flex items-start gap-4">'
                    . '<div class="flex-shrink-0 mt-0.5">' . $icon . '</div>'
                    . '<div class="flex-1">'
                    . '<p class="text-lg font-bold leading-tight ' . $titleColor . '">' . $message . '</p>'
                    . '<p class="mt-2 text-sm text-neutral-600 dark:text-neutral-300">' . $description . '</p>'
                    . '</div>'
                    . '</div>'
                    . '</div>';

                $this->js("toast('', { html: `" . $html . "`, position: 'top-center' })");
            }
        } catch (\Exception $e) {
            // silently fail
        }
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
            'online_percentage' => $this->sanitizeChartValue($stats['online_percentage']),
            'offline_percentage' => $this->sanitizeChartValue($stats['offline_percentage']),
            'timeout_percentage' => $this->sanitizeChartValue($stats['timeout_percentage']),
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
                'normal_percentage' => $this->sanitizeChartValue(round($normalPercentage, 2)),
                
                'high_count' => $highCount,
                'high_minutes' => $highMinutes,
                'high_time' => $highTime,
                'high_percentage' => $this->sanitizeChartValue(round($highPercentage, 2)),
                
                'low_count' => $lowCount,
                'low_minutes' => $lowMinutes,
                'low_time' => $lowTime,
                'low_percentage' => $this->sanitizeChartValue(round($lowPercentage, 2)),
                
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
    
    private function sanitizeChartValue($value): float
    {
        if ($value === null || !is_numeric($value)) {
            return 0;
        }
        $float = (float) $value;
        if (!is_finite($float) || is_nan($float)) {
            return 0;
        }
        return $float;
    }

    private function prepareOnlineChartData(): array
    {
        return [
            'labels' => ['Online', 'Offline', 'Timeout (RTO)'],
            'datasets' => [[
                'data' => [
                    $this->sanitizeChartValue($this->onlineStats['online_percentage'] ?? 0),
                    $this->sanitizeChartValue($this->onlineStats['offline_percentage'] ?? 0),
                    $this->sanitizeChartValue($this->onlineStats['timeout_percentage'] ?? 0),
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
                'data' => [
                    $this->sanitizeChartValue($stats['normal_percentage'] ?? 0),
                    $this->sanitizeChartValue($stats['high_percentage'] ?? 0),
                    $this->sanitizeChartValue($stats['low_percentage'] ?? 0),
                ],
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
            $dosingLogs = InsPhDosingLog::with('device')
                ->where("dosing_amount", ">", 0)
                ->whereBetween("created_at", [Carbon::parse($this->start_at)->startOfDay(), Carbon::parse($this->end_at)->endOfDay()]);

            if ($this->plant) {
                $dosingLogs->whereHas('device', function($q) {
                    $q->where('id', $this->plant);
                });
            }

            $dosingLogs = $dosingLogs->orderBy("created_at", "ASC")->get();

            if ($data->isEmpty()) {
                return $this->getEmptyChartData();
            }

            // Group data by hourly intervals
            $hourlyData = [];
            
            foreach ($data as $count) {
                $timestamp = Carbon::parse($count->created_at);
                $hourKey = $timestamp->format('Y-m-d H:00:00');
                
                if (is_array($count->ph_value)) {
                    $phValue = $count->ph_value['current_ph'] ?? $count->ph_value['current'] ?? null;
                } else {
                    $phValue = null;
                }
                
                if ($phValue === null || !is_numeric($phValue)) {
                    continue;
                }
                
                if (!isset($hourlyData[$hourKey])) {
                    $hourlyData[$hourKey] = [
                        'sum' => 0,
                        'count' => 0,
                        'timestamp' => $timestamp->copy()->startOfHour(),
                    ];
                }
                
                $phFloat = (float) $phValue;
                if (is_finite($phFloat) && !is_nan($phFloat)) {
                    $hourlyData[$hourKey]['sum'] += $phFloat;
                    $hourlyData[$hourKey]['count']++;
                }
            }

            // Collect dosing events with their real timestamps
            $dosingEvents = [];
            foreach ($dosingLogs as $log) {
                $timestamp = Carbon::parse($log->created_at);
                $dataDosing = $log->data_dosing;
                
                $dosingEvents[] = [
                    'timestamp' => $timestamp->getTimestamp() * 1000,
                    'label' => $timestamp->format('H:i'),
                    'dosing_data' => is_array($dataDosing) ? [
                        'total_amount' => (int) ($dataDosing['total_amount'] ?? 0),
                        'formula_1_amount' => (int) ($dataDosing['formula_1_amount'] ?? 0),
                        'formula_2_amount' => (int) ($dataDosing['formula_2_amount'] ?? 0),
                        'formula_3_amount' => (int) ($dataDosing['formula_3_amount'] ?? 0),
                    ] : null,
                ];
            }

            if (empty($hourlyData)) {
                return $this->getEmptyChartData();
            }

            $chartData = [
                'timestamps' => [],
                'phValues' => [],
                'dosingEvents' => $dosingEvents,
            ];

            ksort($hourlyData);
            
            foreach ($hourlyData as $hourKey => $hourInfo) {
                if ($hourInfo['count'] <= 0) {
                    continue;
                }
                
                $avgPh = $hourInfo['sum'] / $hourInfo['count'];
                
                if (!is_finite($avgPh) || is_nan($avgPh)) {
                    continue;
                }
                
                $chartData['timestamps'][] = $hourInfo['timestamp']->getTimestamp() * 1000;
                $chartData['phValues'][] = round($avgPh, 2);
            }

            return $chartData;
        } catch (\Exception $e) {
            return $this->getEmptyChartData();
        }
    }
    
    private function getEmptyChartData(): array
    {
        return [
            'timestamps' => [],
            'phValues' => [],
            'dosingEvents' => [],
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
            <div class="flex items-center gap-3">
                <!-- today  -->
                 <h1>{{ now()->format("Y-m-d H:i:s") }}</h1>
            </div>
            <div class="border-l border-neutral-300 dark:border-neutral-700 mx-2"></div>
            <div class="grid grid-cols-3 gap-3 items-center">
                
                <div class="col-span-1 text-left">
                    <label class="text-sm font-medium mb-2 text-gray-700 dark:text-gray-300">{{ __("Plant") }}</label>
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
                <h2 class="text-2xl font-bold text-neutral-800 dark:text-neutral-200">-</h2>
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
                
                @if(empty($chartData['timestamps']) || count($chartData['timestamps']) === 0)
                    <div class="flex items-center justify-center" style="height: 400px;">
                        <p class="text-neutral-500 dark:text-neutral-400 text-lg">{{ __("No data available for selected filters") }}</p>
                    </div>
                @else
                <div 
                    wire:key="chart-container-{{ md5(json_encode($chartData)) }}"
                    x-data="{ 
                        chart: null,
                        renderTimeout: null,
                        isRendering: false,
                        renderRetries: 0,
                        maxRenderRetries: 10,
                        init() {
                            this.$nextTick(() => this.initChart());
                        },
                        destroy() {
                            this.cleanupChart();
                        },
                        cleanupChart() {
                            if (this.renderTimeout) {
                                clearTimeout(this.renderTimeout);
                                this.renderTimeout = null;
                            }
                            if (this.chart) {
                                try { 
                                    this.chart.destroy(); 
                                } catch(e) {}
                                this.chart = null;
                            }
                        },
                        initChart() {
                            if (typeof ApexCharts === 'undefined') {
                                setTimeout(() => this.initChart(), 100);
                                return;
                            }
                            this.renderChart();
                        },
                        renderChart() {
                            if (this.renderTimeout) {
                                clearTimeout(this.renderTimeout);
                            }
                            this.renderTimeout = setTimeout(() => {
                                this.doRenderChart();
                            }, 50);
                        },
                        doRenderChart() {
                            if (this.isRendering) {
                                return;
                            }
                            
                            this.isRendering = true;
                            
                            try {
                                const chartElement = this.$refs.chartContainer;
                                
                                if (!chartElement || !chartElement.isConnected) {
                                    return;
                                }
                                
                                const rect = chartElement.getBoundingClientRect();
                                if (!rect || rect.width === 0 || rect.height === 0 || !isFinite(rect.width) || !isFinite(rect.height)) {
                                    if (this.renderRetries < this.maxRenderRetries) {
                                        this.renderRetries++;
                                        setTimeout(() => this.renderChart(), 100);
                                    }
                                    return;
                                }
                                
                                this.renderRetries = 0;
                                
                                if (this.chart) {
                                    try { this.chart.destroy(); } catch(e) {}
                                    this.chart = null;
                                }
                                
                                const chartData = @js($chartData);
                                const stdMinPh = {{ is_numeric($stdMinPh) && is_finite($stdMinPh) ? $stdMinPh : 2 }};
                                const stdMaxPh = {{ is_numeric($stdMaxPh) && is_finite($stdMaxPh) ? $stdMaxPh : 3 }};
                                
                                const rawTimestamps = chartData.timestamps || [];
                                const rawPhValues = chartData.phValues || [];
                                const dosingEvents = chartData.dosingEvents || [];
                                
                                // Build pH series as [{x: timestamp_ms, y: value}]
                                const phSeries = [];
                                for (let i = 0; i < rawTimestamps.length; i++) {
                                    const phValue = rawPhValues[i];
                                    const ts = rawTimestamps[i];
                                    if (phValue !== null && 
                                        typeof phValue === 'number' &&
                                        isFinite(phValue) && !isNaN(phValue) &&
                                        ts !== null && isFinite(ts)) {
                                        phSeries.push({ x: ts, y: phValue });
                                    }
                                }
                                
                                if (phSeries.length === 0) {
                                    return;
                                }
                                
                                const safeStdMaxPh = (typeof stdMaxPh === 'number' && isFinite(stdMaxPh) && !isNaN(stdMaxPh)) ? stdMaxPh : 3;
                                const safeStdMinPh = (typeof stdMinPh === 'number' && isFinite(stdMinPh) && !isNaN(stdMinPh)) ? stdMinPh : 2;
                                
                                // Build limit series matching pH timestamps
                                const maxLimit = phSeries.map(p => ({ x: p.x, y: safeStdMaxPh }));
                                const minLimit = phSeries.map(p => ({ x: p.x, y: safeStdMinPh }));
                                
                                const isDark = document.documentElement.classList.contains('dark');
                                const textColor = isDark ? '#d4d4d4' : '#525252';
                                const gridColor = isDark ? '#404040' : '#e5e7eb';
                                
                                // Build xaxis annotations for dosing events at their real timestamps
                                const dosingXAnnotations = [];
                                dosingEvents.forEach(event => {
                                    if (!event || !event.timestamp || !isFinite(event.timestamp)) return;
                                    let labelText = '\u25BC ' + event.label;
                                    if (event.dosing_data) {
                                        labelText += ' (T:' + event.dosing_data.total_amount + 'gr)';
                                    }
                                    dosingXAnnotations.push({
                                        x: event.timestamp,
                                        borderColor: '#10b981',
                                        strokeDashArray: 4,
                                        label: {
                                            text: labelText,
                                            borderColor: '#10b981',
                                            style: {
                                                background: '#10b981',
                                                color: '#fff',
                                                fontSize: '10px',
                                                padding: { left: 4, right: 4, top: 2, bottom: 2 }
                                            },
                                            orientation: 'horizontal',
                                            position: 'top'
                                        }
                                    });
                                });
                                
                                const hasNaN = phSeries.some(v => !isFinite(v.y) || isNaN(v.y));
                                if (hasNaN) {
                                    return;
                                }
                                
                                const options = {
                                    series: [
                                        { name: 'Nilai pH', data: phSeries },
                                        { name: 'Batas Maksimal (pH ' + safeStdMaxPh + ')', data: maxLimit },
                                        { name: 'Batas Minimal (pH ' + safeStdMinPh + ')', data: minLimit }
                                    ],
                                    chart: {
                                        width: rect.width || '100%',
                                        height: 400,
                                        type: 'line',
                                        toolbar: {
                                            show: true,
                                            tools: { download: true, selection: false, zoom: true, zoomin: true, zoomout: true, pan: false, reset: true }
                                        },
                                        animations: { enabled: true, easing: 'easeinout', speed: 800 },
                                        background: 'transparent'
                                    },
                                    annotations: {
                                        xaxis: dosingXAnnotations
                                    },
                                    colors: ['#3b82f6', '#ef4444', '#ef4444'],
                                    stroke: { width: [3, 2, 2], curve: phSeries.length < 3 ? 'straight' : 'smooth', dashArray: [0, 5, 5] },
                                    markers: {
                                        size: [4, 0.1, 0.1],
                                        colors: ['#3b82f6', 'transparent', 'transparent'],
                                        strokeColors: ['#fff', 'transparent', 'transparent'],
                                        strokeWidth: 2,
                                        hover: { sizeOffset: 3 }
                                    },
                                    dataLabels: { enabled: false },
                                    xaxis: {
                                        type: 'datetime',
                                        title: { text: 'Waktu', style: { fontSize: '12px', color: textColor } },
                                        labels: {
                                            datetimeUTC: false,
                                            style: { colors: textColor, fontSize: '11px' },
                                            datetimeFormatter: {
                                                year: 'yyyy',
                                                month: 'MMM yyyy',
                                                day: 'dd MMM',
                                                hour: 'HH:mm'
                                            }
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
                                                if (value === null || value === undefined || isNaN(value) || !isFinite(value)) return '0.0';
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
                                        x: {
                                            format: 'dd/MM HH:mm'
                                        },
                                        y: [
                                            { 
                                                formatter: function(value) { 
                                                    if (value === null || value === undefined || isNaN(value) || !isFinite(value)) return 'N/A';
                                                    return Number(value).toFixed(2); 
                                                } 
                                            },
                                            { 
                                                formatter: function(value) { 
                                                    if (value === null || value === undefined || isNaN(value) || !isFinite(value)) return 'N/A';
                                                    return Number(value).toFixed(2); 
                                                } 
                                            },
                                            { 
                                                formatter: function(value) { 
                                                    if (value === null || value === undefined || isNaN(value) || !isFinite(value)) return 'N/A';
                                                    return Number(value).toFixed(2); 
                                                } 
                                            }
                                        ]
                                    },
                                    theme: { mode: isDark ? 'dark' : 'light' }
                                };
                                
                                try {
                                    this.chart = new ApexCharts(chartElement, options);
                                    this.chart.render();
                                } catch (e) {
                                    this.cleanupChart();
                                }
                            } finally {
                                this.isRendering = false;
                            }
                        }
                    }"
                    x-init="
                        $nextTick(() => initChart());
                        $el.addEventListener('livewire:navigating', () => cleanupChart());
                    "
                    @caldera-theme-changed.window="renderChart()"
                >
                    <div x-ref="chartContainer" wire:ignore style="height: 400px; min-height: 400px;"></div>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>

@script
    <script>
        let onlineChart;
        let statusChart;

        function sanitizeChartData(chartData) {
            if (!chartData || !chartData.datasets || !chartData.datasets[0] || !chartData.datasets[0].data) {
                return null;
            }
            chartData.datasets[0].data = chartData.datasets[0].data.map(function(v) {
                if (v === null || v === undefined || typeof v !== 'number' || isNaN(v) || !isFinite(v)) {
                    return 0;
                }
                return v;
            });
            const total = chartData.datasets[0].data.reduce(function(a, b) { return a + b; }, 0);
            if (total === 0) {
                return null;
            }
            return chartData;
        }

        function initOnlineChart(onlineData) {
            const onlineCtx = document.getElementById('onlineChart');
            
            if (!onlineCtx) {
                return;
            }
            
            try {
                const existingOnlineChart = Chart.getChart(onlineCtx);
                if (existingOnlineChart) {
                    existingOnlineChart.destroy();
                }
                
                const sanitized = sanitizeChartData(onlineData);
                if (!sanitized) {
                    return;
                }
                
                onlineChart = new Chart(onlineCtx, {
                    type: 'doughnut',
                    data: sanitized,
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
            } catch (error) {
                console.error('Error initializing online chart:', error);
            }
        }

        function initStatusChart(statusData) {
            const statusCtx = document.getElementById('statusChart');
            
            if (!statusCtx) {
                return;
            }
            
            try {
                const existingStatusChart = Chart.getChart(statusCtx);
                if (existingStatusChart) {
                    existingStatusChart.destroy();
                }
                
                const sanitized = sanitizeChartData(statusData);
                if (!sanitized) {
                    return;
                }
                
                statusChart = new Chart(statusCtx, {
                    type: 'doughnut',
                    data: sanitized,
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
            } catch (error) {
                console.error('Error initializing status chart:', error);
            }
        }

        // Listen for refresh events
        Livewire.on('refresh-online-chart', (event) => {
            try {
                const data = event[0] || event;
                if (data && data.onlineData) {
                    initOnlineChart(data.onlineData);
                }
            } catch (error) {
                console.error('Error handling refresh-online-chart event:', error);
            }
        });

        Livewire.on('refresh-status-chart', (event) => {
            try {
                const data = event[0] || event;
                if (data && data.statusData) {
                    initStatusChart(data.statusData);
                }
            } catch (error) {
                console.error('Error handling refresh-status-chart event:', error);
            }
        });

        // Initial load
        let onlineChartRetries = 0;
        let statusChartRetries = 0;
        const MAX_RETRIES = 10;
        
        const initializeOnlineChart = () => {
            const onlineData = @json($this->prepareOnlineChartData());
            
            // Wait for DOM to be ready
            if (document.getElementById('onlineChart')) {
                initOnlineChart(onlineData);
                onlineChartRetries = 0;
            } else if (onlineChartRetries < MAX_RETRIES) {
                // Retry after a short delay if element not found
                onlineChartRetries++;
                setTimeout(initializeOnlineChart, 100);
            } else {
                console.warn('Online chart element not found after max retries');
            }
        };

        const initializeStatusChart = () => {
            const statusData = @json($this->prepareStatusChartData());
            
            // Wait for DOM to be ready
            if (document.getElementById('statusChart')) {
                initStatusChart(statusData);
                statusChartRetries = 0;
            } else if (statusChartRetries < MAX_RETRIES) {
                // Retry after a short delay if element not found
                statusChartRetries++;
                setTimeout(initializeStatusChart, 100);
            } else {
                console.warn('Status chart element not found after max retries');
            }
        };

        // Run after Livewire component is loaded
        document.addEventListener('DOMContentLoaded', function() {
            initializeOnlineChart();
            initializeStatusChart();
        });
        
        // Also try immediately in case DOMContentLoaded already fired
        if (document.readyState === 'loading') {
            // DOM not ready yet
        } else {
            // DOM is ready
            initializeOnlineChart();
            initializeStatusChart();
        }
    </script>
    @endscript
</div>