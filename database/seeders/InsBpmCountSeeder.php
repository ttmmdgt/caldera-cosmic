<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\InsBpmCount;
use Carbon\Carbon;

class InsBpmCountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Define lines and machines
        $plant = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J'];
        $lines = ['1', '2', '3', '4', '5'];
        $machines = ['M1', 'M2', 'M3', 'M4'];
        $conditions = ['hot', 'cold'];

        // Generate data for the last 30 days
        $startDate = Carbon::now()->subDays(30);
        $endDate = Carbon::now();

        $this->command->info('Generating BPM Count data...');
        $progressBar = $this->command->getOutput()->createProgressBar(30 * count($lines) * count($machines));

        // Track cumulative values per line-machine-condition combination
        $cumulatives = [];

        for ($date = $startDate->copy(); $date <= $endDate; $date->addDay()) {
            foreach ($lines as $line) {
                foreach ($machines as $machine) {
                    // Each machine can have both hot and cold conditions per day
                    foreach ($conditions as $condition) {
                        $key = "{$line}_{$machine}_{$condition}";
                        $plantSelected = $plant[array_rand($plant)];
                        // Initialize cumulative if not exists
                        if (!isset($cumulatives[$key])) {
                            $cumulatives[$key] = rand(100, 500);
                        }

                        // Generate 8-12 entries per day (simulating push button events)
                        $entriesPerDay = rand(8, 12);
                        
                        for ($i = 0; $i < $entriesPerDay; $i++) {
                            // Random time during work hours (7:00 - 17:00)
                            $hour = rand(7, 16);
                            $minute = rand(0, 59);
                            $second = rand(0, 59);
                            
                            $timestamp = $date->copy()
                                ->setHour($hour)
                                ->setMinute($minute)
                                ->setSecond($second);

                            // Skip if timestamp is in the future
                            if ($timestamp > Carbon::now()) {
                                continue;
                            }

                            // Random incremental value (emergency button presses)
                            // Most of the time 0-2, occasionally higher (0-5)
                            $incremental = rand(0, 100) < 70 ? rand(0, 2) : rand(3, 5);
                            
                            // Update cumulative
                            $cumulatives[$key] += $incremental;

                            InsBpmCount::create([
                                'line' => $line,
                                'plant' => $plantSelected,
                                'machine' => $machine,
                                'condition' => $condition,
                                'incremental' => $incremental,
                                'cumulative' => $cumulatives[$key],
                                'created_at' => $timestamp,
                                'updated_at' => $timestamp,
                            ]);
                        }
                        
                        $progressBar->advance();
                    }
                }
            }
        }

        $progressBar->finish();
        $this->command->newLine();
        $this->command->info('BPM Count data generated successfully!');
        
        // Display summary
        $totalRecords = InsBpmCount::count();
        $this->command->info("Total records created: {$totalRecords}");
        
        $this->command->newLine();
        $this->command->table(
            ['Line', 'Machine', 'Condition', 'Total Cumulative', 'Total Incremental', 'Records'],
            collect($lines)->flatMap(function ($line) use ($machines, $conditions) {
                return collect($machines)->flatMap(function ($machine) use ($line, $conditions) {
                    return collect($conditions)->map(function ($condition) use ($line, $machine) {
                        $counts = InsBpmCount::where('line', $line)
                            ->where('machine', $machine)
                            ->where('condition', $condition)
                            ->selectRaw('MAX(cumulative) as max_cumulative, SUM(incremental) as total_incremental, COUNT(*) as total_records')
                            ->first();
                        
                        return [
                            $line,
                            $machine,
                            $condition,
                            number_format($counts->max_cumulative ?? 0),
                            number_format($counts->total_incremental ?? 0),
                            number_format($counts->total_records ?? 0),
                        ];
                    });
                });
            })->take(10)->toArray()
        );
        
        $this->command->info('(Showing first 10 combinations...)');
    }
}
