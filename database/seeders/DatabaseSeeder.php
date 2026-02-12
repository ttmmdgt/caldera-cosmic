<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);

        // Project and Working Hours Configuration Seeders
        $this->call([
            ProjectSeeder::class,
            ShiftSeeder::class,
            ProjectWorkingHourSeeder::class,
        ]);

        // DWP (Deep-Well Press) seeders
        if (app()->environment(['local', 'testing'])) {
            $this->call([
                InsDwpDeviceSeeder::class,
                InsDwpCountSeeder::class,
                InsDwpTimeAlarmCountSeeder::class,
                InsBpmCountSeeder::class, // BPM (Button Push Monitor) seeder
            ]);
        }
    }
}
