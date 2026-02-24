<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\InsIbmsCount;
use Carbon\Carbon;

class InsIbmsCountSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Generating IP Blend Count data...');

        $shifts = ['A', 'B', 'C'];
        $startDate = Carbon::now()->subDays(30);
        $endDate = Carbon::now();

        $progressBar = $this->command->getOutput()->createProgressBar(30 * count($shifts));

        for ($date = $startDate->copy(); $date <= $endDate; $date->addDay()) {
            foreach ($shifts as $shift) {
                $timestamp = $date->copy()->setHour(rand(0, 23))->setMinute(rand(0, 59))->setSecond(rand(0, 59));

                if ($timestamp > Carbon::now()) {
                    continue;
                }

                $durationMinutes = rand(60, 480);
                $hours = intdiv($durationMinutes, 60);
                $minutes = $durationMinutes % 60;
                $duration = sprintf('%02d:%02d:00', $hours, $minutes);

                InsIbmsCount::create([
                    'shift' => $shift,
                    'duration' => $duration,
                    'data' => [
                        'name' => (string) rand(1, 3),
                        'status' => ['to_early', 'to_late', 'on_time'][array_rand(['to_early', 'to_late', 'on_time'])],
                    ],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->command->newLine();
        $this->command->info('IP Blend Count data generated successfully!');

        $totalRecords = InsIbmsCount::count();
        $this->command->info("Total records created: {$totalRecords}");

        $this->command->newLine();
        $this->command->table(
            ['Shift', 'Records', 'Avg Duration'],
            collect($shifts)->map(function ($shift) {
                $counts = InsIbmsCount::where('shift', $shift)->get();
                $avgDuration = $counts->isNotEmpty() ? $counts->avg(function ($c) {
                    $parts = explode(':', $c->duration);
                    return ($parts[0] ?? 0) * 60 + ($parts[1] ?? 0);
                }) : 0;
                return [
                    $shift,
                    number_format($counts->count()),
                    $counts->isNotEmpty() ? number_format($avgDuration, 0) . ' min' : 'N/A',
                ];
            })->toArray()
        );
    }
}
