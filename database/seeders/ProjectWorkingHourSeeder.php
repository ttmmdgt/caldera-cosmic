<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Shift;
use App\Models\ProjectWorkingHour;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProjectWorkingHourSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get unique project groups
        $projectGroups = Project::distinct()->pluck('project_group');
        $shifts = Shift::active()->ordered()->get();

        // Set default working hours for all project groups and shifts
        foreach ($projectGroups as $projectGroup) {
            foreach ($shifts as $shift) {
                ProjectWorkingHour::updateOrCreate(
                    [
                        'project_group' => $projectGroup,
                        'shift_id' => $shift->id,
                    ],
                    [
                        'work_start_time' => $shift->start_time,
                        'work_end_time' => $shift->end_time,
                        'is_working_day' => true,
                        'break_times' => [
                            [
                                'start' => '12:00',
                                'end' => '13:00',
                            ]
                        ],
                    ]
                );
            }
        }

        $this->command->info('Project working hours seeded successfully!');
    }
}
