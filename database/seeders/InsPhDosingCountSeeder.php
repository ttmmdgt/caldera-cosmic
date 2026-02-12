<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\InsPhDosingDevice;
use App\Models\InsPhDosingCount;
use Carbon\Carbon;

class InsPhDosingCountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // First, ensure we have devices to seed counts for
        $this->ensureDevicesExist();

        $devices = InsPhDosingDevice::where('is_active', true)->get();

        if ($devices->isEmpty()) {
            $this->command->warn('No active PH Dosing devices found. Please seed devices first.');
            return;
        }

        // Generate data for the last 30 days
        $startDate = Carbon::now()->subDays(30);
        $endDate = Carbon::now();

        $this->command->info('Generating PH Dosing Count data...');
        $progressBar = $this->command->getOutput()->createProgressBar(30 * $devices->count());

        for ($date = $startDate->copy(); $date <= $endDate; $date->addDay()) {
            foreach ($devices as $device) {
                // Generate 24-48 entries per day (monitoring every 30-60 minutes)
                $entriesPerDay = rand(24, 48);
                
                for ($i = 0; $i < $entriesPerDay; $i++) {
                    // Random time throughout the day
                    $hour = rand(0, 23);
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

                    // Generate realistic pH values (typically 6.5 - 8.5 for industrial water)
                    // with some occasional outliers that might trigger dosing
                    $phValue = $this->generatePhValue();

                    InsPhDosingCount::create([
                        'device_id' => $device->id,
                        'ph_value' => $phValue,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                }
                
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->command->newLine();
        $this->command->info('PH Dosing Count data generated successfully!');
        
        // Display summary
        $totalRecords = InsPhDosingCount::count();
        $this->command->info("Total records created: {$totalRecords}");
        
        $this->command->newLine();
        $this->command->table(
            ['Device', 'Plant', 'Records', 'Avg pH', 'Min pH', 'Max pH'],
            $devices->map(function ($device) {
                $counts = InsPhDosingCount::where('device_id', $device->id)
                    ->get();
                
                $phValues = $counts->map(function ($count) {
                    $phData = is_string($count->ph_value) 
                        ? json_decode($count->ph_value, true) 
                        : $count->ph_value;
                    return $phData['current'] ?? 7.0;
                });
                
                return [
                    $device->name,
                    $device->plant,
                    number_format($counts->count()),
                    $phValues->isNotEmpty() ? number_format($phValues->avg(), 2) : 'N/A',
                    $phValues->isNotEmpty() ? number_format($phValues->min(), 2) : 'N/A',
                    $phValues->isNotEmpty() ? number_format($phValues->max(), 2) : 'N/A',
                ];
            })->toArray()
        );
    }

    /**
     * Ensure at least some devices exist for testing
     */
    private function ensureDevicesExist(): void
    {
        if (InsPhDosingDevice::count() === 0) {
            $this->command->info('Creating sample PH Dosing devices...');
            
            $plants = ['A', 'B', 'C'];
            
            foreach ($plants as $index => $plant) {
                InsPhDosingDevice::create([
                    'name' => "PH Dosing Device - Plant {$plant}",
                    'plant' => $plant,
                    'ip_address' => "192.168.1." . (100 + $index),
                    'config' => [
                        'target_ph' => 7.5,
                        'min_ph' => 6.5,
                        'max_ph' => 8.5,
                        'dosing_rate' => 100,
                        'monitoring_interval' => 1800, // 30 minutes
                    ],
                    'is_active' => true,
                ]);
            }
            
            $this->command->info('Sample devices created successfully!');
            $this->command->newLine();
        }
    }

    /**
     * Generate realistic pH value with some variation
     */
    private function generatePhValue(): array
    {
        // Base pH around 7.0-7.5 (neutral to slightly alkaline)
        $basePh = 7.0 + (rand(0, 50) / 100);
        
        // Add some random variation (±0.5)
        $variation = (rand(-50, 50) / 100);
        $currentPh = round($basePh + $variation, 2);
        
        // Occasionally generate outliers (5% chance)
        if (rand(1, 100) <= 5) {
            $currentPh = rand(1, 100) > 50 
                ? round(6.0 + (rand(0, 40) / 100), 2)  // Low pH
                : round(8.6 + (rand(0, 40) / 100), 2); // High pH
        }
        
        // Ensure pH stays within realistic bounds (4.0 - 10.0)
        $currentPh = max(4.0, min(10.0, $currentPh));
        
        return [
            'current' => $currentPh,
            'target' => 7.5,
            'min' => 6.5,
            'max' => 8.5,
            'status' => $this->getPhStatus($currentPh),
            'timestamp' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Determine pH status
     */
    private function getPhStatus(float $ph): string
    {
        if ($ph < 6.5) {
            return 'low';
        } elseif ($ph > 8.5) {
            return 'high';
        } elseif ($ph >= 7.0 && $ph <= 8.0) {
            return 'optimal';
        } else {
            return 'normal';
        }
    }
}
