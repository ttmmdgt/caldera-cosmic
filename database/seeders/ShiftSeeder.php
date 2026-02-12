<?php

namespace Database\Seeders;

use App\Models\Shift;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ShiftSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $shifts = [
            [
                'name' => 'Shift 1',
                'code' => '1',
                'start_time' => '07:00:00',
                'end_time' => '15:00:00',
                'description' => 'Morning Shift',
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Shift 2',
                'code' => '2',
                'start_time' => '15:00:00',
                'end_time' => '23:00:00',
                'description' => 'Afternoon Shift',
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Shift 3',
                'code' => '3',
                'start_time' => '23:00:00',
                'end_time' => '07:00:00',
                'description' => 'Night Shift',
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'Non Shift',
                'code' => 'NS',
                'start_time' => '07:00:00',
                'end_time' => '16:00:00',
                'description' => 'Non Shift',
                'is_active' => true,
                'sort_order' => 5,
            ],
        ];

        foreach ($shifts as $shift) {
            Shift::updateOrCreate(
                ['code' => $shift['code']],
                $shift
            );
        }

        $this->command->info('Shifts seeded successfully!');
    }
}
