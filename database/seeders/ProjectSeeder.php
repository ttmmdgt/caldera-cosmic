<?php

namespace Database\Seeders;

use App\Models\Project;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get projects from config file
        $projects = config('uptime.projects', []);

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
        }

        $this->command->info('Projects seeded successfully!');
    }
}
