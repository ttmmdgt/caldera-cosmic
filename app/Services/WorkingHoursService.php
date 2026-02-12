<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Shift;
use App\Models\ProjectWorkingHour;
use Carbon\Carbon;

class WorkingHoursService
{
    /**
     * Check if a project is currently working
     */
    public function isProjectWorking(int $projectId, ?Carbon $dateTime = null): bool
    {
        $project = Project::find($projectId);
        
        if (!$project) {
            return false;
        }
        
        $dateTime = $dateTime ?? now();
        $time = $dateTime->format('H:i:s');
        
        $shift = $this->getCurrentShift($time);
        
        if (!$shift) {
            return false;
        }
        
        $workingHour = ProjectWorkingHour::forProjectGroup($project->project_group)
            ->forShift($shift->id)
            ->workingDays()
            ->first();
        
        return $workingHour !== null;
    }

    /**
     * Get the current active shift based on time
     */
    public function getCurrentShift(?string $time = null): ?Shift
    {
        $time = $time ?? now()->format('H:i:s');
        
        // Handle shifts that span midnight (like 23:00-07:00)
        $shift = Shift::active()
            ->where(function($query) use ($time) {
                // Normal shifts (start < end)
                $query->where(function($q) use ($time) {
                    $q->whereRaw('start_time <= end_time')
                      ->where('start_time', '<=', $time)
                      ->where('end_time', '>', $time);
                })
                // Midnight-spanning shifts (start > end)
                ->orWhere(function($q) use ($time) {
                    $q->whereRaw('start_time > end_time')
                      ->where(function($q2) use ($time) {
                          $q2->where('start_time', '<=', $time)
                             ->orWhere('end_time', '>', $time);
                      });
                });
            })
            ->ordered()
            ->first();
        
        return $shift;
    }

    /**
     * Get all working hours for a project
     */
    public function getProjectWorkingHours(int $projectId): array
    {
        $project = Project::find($projectId);
        
        if (!$project) {
            return [];
        }
        
        $workingHours = ProjectWorkingHour::forProjectGroup($project->project_group)
            ->with('shift')
            ->workingDays()
            ->get();
        
        return $workingHours->map(function($wh) {
            return [
                'shift_name' => $wh->shift->name,
                'shift_code' => $wh->shift->code,
                'start_time' => $wh->work_start_time,
                'end_time' => $wh->work_end_time,
                'break_times' => $wh->break_times,
            ];
        })->toArray();
    }

    /**
     * Get all project groups working in a specific shift
     */
    public function getProjectGroupsByShift(int $shiftId): array
    {
        $workingHours = ProjectWorkingHour::forShift($shiftId)
            ->workingDays()
            ->get();
        
        return $workingHours->pluck('project_group')->unique()->toArray();
    }

    /**
     * Get all projects by group with their working hours
     */
    public function getProjectsByGroup(string $group): array
    {
        $projects = Project::byGroup($group)
            ->active()
            ->with(['workingHours.shift'])
            ->get();
        
        return $projects->map(function($project) {
            return [
                'id' => $project->id,
                'name' => $project->name,
                'ip' => $project->ip,
                'type' => $project->type,
                'working_hours' => $project->workingHours->where('is_working_day', true)->map(function($wh) {
                    return [
                        'shift' => $wh->shift->name,
                        'start' => $wh->work_start_time,
                        'end' => $wh->work_end_time,
                    ];
                })->values()->toArray(),
            ];
        })->toArray();
    }

    /**
     * Update or create working hours for a project group
     */
    public function setProjectGroupWorkingHours(
        string $projectGroup,
        int $shiftId,
        string $startTime,
        string $endTime,
        bool $isWorkingDay = true,
        ?array $breakTimes = null
    ): ProjectWorkingHour {
        return ProjectWorkingHour::updateOrCreate(
            [
                'project_group' => $projectGroup,
                'shift_id' => $shiftId,
            ],
            [
                'work_start_time' => $startTime,
                'work_end_time' => $endTime,
                'is_working_day' => $isWorkingDay,
                'break_times' => $breakTimes ?? [
                    ['start' => '12:00', 'end' => '13:00']
                ],
            ]
        );
    }

    /**
     * Disable a shift for a project group
     */
    public function disableShiftForProjectGroup(string $projectGroup, int $shiftId): bool
    {
        return ProjectWorkingHour::where('project_group', $projectGroup)
            ->where('shift_id', $shiftId)
            ->update(['is_working_day' => false]) > 0;
    }

    /**
     * Enable a shift for a project group
     */
    public function enableShiftForProjectGroup(string $projectGroup, int $shiftId): bool
    {
        return ProjectWorkingHour::where('project_group', $projectGroup)
            ->where('shift_id', $shiftId)
            ->update(['is_working_day' => true]) > 0;
    }

    /**
     * Get statistics for all projects and shifts
     */
    public function getStatistics(): array
    {
        return [
            'total_projects' => Project::count(),
            'active_projects' => Project::active()->count(),
            'total_shifts' => Shift::count(),
            'active_shifts' => Shift::active()->count(),
            'working_configurations' => ProjectWorkingHour::workingDays()->count(),
            'projects_by_group' => Project::selectRaw('project_group, COUNT(*) as count')
                ->groupBy('project_group')
                ->pluck('count', 'project_group')
                ->toArray(),
            'projects_by_type' => Project::selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray(),
        ];
    }

    /**
     * Sync projects from config file to database
     */
    public function syncProjectsFromConfig(): int
    {
        $projects = config('uptime.projects', []);
        $count = 0;

        foreach ($projects as $projectData) {
            Project::updateOrCreate(
                [
                    'name' => $projectData['name'],
                    'ip' => $projectData['ip'],
                ],
                [
                    'project_group' => $projectData['project_group'],
                    'timeout' => $projectData['timeout'] ?? 10,
                    'type' => $projectData['type'],
                    'modbus_config' => $projectData['modbus_config'] ?? null,
                    'is_active' => true,
                ]
            );
            $count++;
        }

        return $count;
    }
}
