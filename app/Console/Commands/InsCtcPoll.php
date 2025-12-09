<?php

namespace App\Console\Commands;

use App\Models\InsCtcMachine;
use App\Models\InsCtcMetric;
use App\Models\InsCtcRecipe;
use Carbon\Carbon;
use Illuminate\Console\Command;
use ModbusTcpClient\Composer\Read\ReadCoilsBuilder;
use ModbusTcpClient\Composer\Read\ReadRegistersBuilder;
use ModbusTcpClient\Network\NonBlockingClient;
use Illuminate\Support\Facades\Cache;

class InsCtcPoll extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:ins-ctc-poll {--v : Verbose output} {--d : Debug output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll rubber thickness data from Modbus servers and save as aggregated batch metrics';

    // Configuration
    protected $batch_timeout = 60;        // seconds - same as old clump timeout, def 60
    protected $minimum_measurements = 10; // minimum measurements per batch (configurable), def 10
    // State management arrays (per machine)
    protected $batch_buffers = [];        // Raw measurement data per machine
    protected $last_activity = [];       // Last measurement timestamp per machine
    protected $sensor_prev = [];         // Previous sensor readings per machine
    protected $recipe_cache = [];        // Cached recipe targets
    protected $recipe_id_prev = [];      // Previous recipe ID per machine
    protected $st_cl_prev = [];          // Previous system time correction left
    protected $st_cr_prev = [];          // Previous system time correction right

    /**
     * Convert integer value to decimal format for thickness measurements
     */
    public function convertToDecimal($value)
    {
        $value = (int) $value;
        $length = strlen((string) $value);

        if ($length == 3) {
            $decimal = substr((string) $value, 0, -2).'.'.substr((string) $value, -2);
        } elseif ($length == 2) {
            $decimal = '0.'.(string) $value;
        } elseif ($length == 1) {
            $decimal = '0.0'.(string) $value;
        } else {
            $decimal = '0.00';
        }
        return (float) $decimal;
    }

    /**
     * Convert push time values to decimal format
     */
    public function convertPushTime($value)
    {
        $value = (int) $value;
        $length = strlen((string) $value);

        if ($length == 3 || $length == 2) {
            $decimal = substr((string) $value, 0, -1).'.'.substr((string) $value, -1);
        } elseif ($length == 1) {
            $decimal = '0.'.(string) $value;
        } else {
            $decimal = '0.0';
        }

        return (float) $decimal;
    }

    public function getActionCode($push_thin, $push_thick)
    {
        if ($push_thin && ! $push_thick) {
            return 1; // Push thin
        } elseif (! $push_thin && $push_thick) {
            return 2; // Push thick
        }

        return 0; // No action
    }

    public function getRecipeTarget($recipe_id)
    {
        if (! $recipe_id) {
            return null;
        }
        if (! isset($this->recipe_cache[$recipe_id])) {
            $recipe = InsCtcRecipe::find($recipe_id);
            $this->recipe_cache[$recipe_id] = $recipe ? $recipe->target : null;
        }
        return $this->recipe_cache[$recipe_id];
    }

    public function calculateBatchStatistics($batch_data, $target = null)
    {
        if (empty($batch_data)) {
            return null;
        }

        $left_values = [];
        $right_values = [];
        $errors_left = [];
        $errors_right = [];

        foreach ($batch_data as $record) {
            $left_values[] = $record[4];    // left thickness
            $right_values[] = $record[5];   // right thickness
            $errors_left[] = $record[10];   // error_left (can be null)
            $errors_right[] = $record[11];  // error_right (can be null)
        }

        // Filter out thickness values <= 0 (invalid sensor readings)
        $valid_left_values = array_filter($left_values, fn ($value) => $value > 0);
        $valid_right_values = array_filter($right_values, fn ($value) => $value > 0);

        // Recalculate averages using only valid values
        if (count($valid_left_values) > 0) {
            $t_avg_left = array_sum($valid_left_values) / count($valid_left_values);
        } else {
            $t_avg_left = 0;
        }

        if (count($valid_right_values) > 0) {
            $t_avg_right = array_sum($valid_right_values) / count($valid_right_values);
        } else {
            $t_avg_right = 0;
        }

        $t_avg = ($t_avg_left + $t_avg_right) / 2;

        // Calculate proper Sample Standard Deviation
        $n_left = count($valid_left_values);
        $n_right = count($valid_right_values);

        // Calculate sum of squared differences using valid values only
        $ssd_left = 0;
        $ssd_right = 0;
        foreach ($valid_left_values as $value) {
            $ssd_left += pow($value - $t_avg_left, 2);
        }
        foreach ($valid_right_values as $value) {
            $ssd_right += pow($value - $t_avg_right, 2);
        }

        // Apply proper sample standard deviation formula
        $t_ssd_left = $n_left > 1 ? sqrt($ssd_left / ($n_left - 1)) : 0;
        $t_ssd_right = $n_right > 1 ? sqrt($ssd_right / ($n_right - 1)) : 0;

        // Combined SSD using all valid values
        $all_valid_values = array_merge($valid_left_values, $valid_right_values);
        $total_n = count($all_valid_values);
        if ($total_n > 1) {
            $combined_avg = array_sum($all_valid_values) / $total_n;
            $combined_ssd = 0;
            foreach ($all_valid_values as $value) {
                $combined_ssd += pow($value - $combined_avg, 2);
            }
            $t_ssd = sqrt($combined_ssd / ($total_n - 1));
        } else {
            $t_ssd = 0;
        }

        // Calculate MAE from pre-calculated errors
        $t_mae_left = null;
        $t_mae_right = null;
        $t_mae = null;

        // Filter out null values but keep 0 values for left side
        $valid_errors_left = array_filter($errors_left, fn ($error) => $error !== null);
        if (count($valid_errors_left) > 0) {
            $t_mae_left = array_sum($valid_errors_left) / count($valid_errors_left);
        }

        // Filter out null values but keep 0 values for right side
        $valid_errors_right = array_filter($errors_right, fn ($error) => $error !== null);
        if (count($valid_errors_right) > 0) {
            $t_mae_right = array_sum($valid_errors_right) / count($valid_errors_right);
        }

        // Combined MAE only if both sides have valid data
        if ($t_mae_left !== null && $t_mae_right !== null) {
            $t_mae = ($t_mae_left + $t_mae_right) / 2;
        } elseif ($t_mae_left !== null) {
            $t_mae = $t_mae_left;
        } elseif ($t_mae_right !== null) {
            $t_mae = $t_mae_right;
        }

        // Calculate balance (difference between left and right averages)
        $t_balance = abs($t_avg_left - $t_avg_right);

        return [
            't_mae_left' => $t_mae_left,
            't_mae_right' => $t_mae_right,
            't_mae' => $t_mae,
            't_ssd_left' => $t_ssd_left,
            't_ssd_right' => $t_ssd_right,
            't_ssd' => $t_ssd,
            't_avg_left' => $t_avg_left,
            't_avg_right' => $t_avg_right,
            't_avg' => $t_avg,
            't_balance' => $t_balance,
        ];
    }

    public function processBatch($machine_id, $batch_data)
    {
        $batch_size = count($batch_data);

        if ($batch_size < $this->minimum_measurements) {
            if ($this->option('d')) {
                $this->line("Batch too small ({$batch_size} < {$this->minimum_measurements}), discarding");
            }
            return;
        }

        $machine = InsCtcMachine::find($machine_id);
        if (!$machine) {
            $this->error("✗ Machine not found: {$machine_id}");
            return;
        }

        if ($this->option('v')) {
            $this->comment("→ Processing batch: {$machine->name}, {$batch_size} measurements");
        }

        // Count recipe occurrences to determine most frequent
        $recipe_counts = [];
        foreach ($batch_data as $record) {
            $recipe_id = $record[6]; // position 6: recipe_id
            $recipe_counts[$recipe_id] = ($recipe_counts[$recipe_id] ?? 0) + 1;
        }

        // Get most frequent recipe_id
        arsort($recipe_counts);
        $batch_recipe_id = array_keys($recipe_counts)[0];
        $most_frequent_count = $recipe_counts[$batch_recipe_id];
        $percentage = ($most_frequent_count / $batch_size) * 100;

        if ($this->option('v')) {
            $this->comment("→ Batch recipe: {$batch_recipe_id} ({$most_frequent_count}/{$batch_size} = {$percentage}%)");
            if (count($recipe_counts) > 1) {
                $this->comment('→ Recipe distribution: ' . json_encode($recipe_counts));
            }
        }

        // 🆕 GET RECIPE REFERENCE (rekomendasi dari database)
        $recipe = InsCtcRecipe::find($batch_recipe_id);
        $recipe_std_min = $recipe ? $recipe->std_min : null;
        $recipe_std_mid = $recipe ? $recipe->std_mid : null;
        $recipe_std_max = $recipe ? $recipe->std_max : null;

        // 🆕 GET ACTUAL OPERATOR INPUT (dari Modbus)
        $last_measurement = end($batch_data);
        $actual_std_min = $last_measurement[7] ?? null;  // std_min dari Modbus
        $actual_std_mid = $last_measurement[9] ?? null;  // std_mid dari Modbus  
        $actual_std_max = $last_measurement[8] ?? null;  // std_max dari Modbus

        if ($this->option('v')) {
            $this->comment("→ Recipe Standards: MIN={$recipe_std_min}, MID={$recipe_std_mid}, MAX={$recipe_std_max}");
            $this->comment("→ Actual Standards: MIN={$actual_std_min}, MID={$actual_std_mid}, MAX={$actual_std_max}");
        }

        // 🆕 WARNING jika deviasi signifikan
        if ($recipe_std_mid && $actual_std_mid) {
            $deviation_mm = $actual_std_mid - $recipe_std_mid;
            $deviation_percent = abs(($deviation_mm / $recipe_std_mid) * 100);
            
            if ($deviation_percent > 20) {
                $this->warn("⚠ Operator input ({$actual_std_mid}mm) deviates " . 
                        round($deviation_percent, 1) . "% from recipe ({$recipe_std_mid}mm)");
            }
        }

        // Calculate statistics
        $stats = $this->calculateBatchStatistics($batch_data);

        if (!$stats) {
            $this->error("✗ Failed to calculate statistics for batch (Machine: {$machine->name})");
            return;
        }

        // Calculate correction uptime percentage
        $correcting_count = 0;
        foreach ($batch_data as $record) {
            if ($record[1]) { // position 1: is_correcting
                $correcting_count++;
            }
        }
        $correction_uptime = $batch_size > 0 ? (int) round(($correcting_count / $batch_size) * 100) : 0;

        // Determine is_auto based on correction uptime (must be done before counting corrections)
        // $is_auto = $correction_uptime > 50;
        $is_auto = $correction_uptime > 30;

        // Count actual corrections by side
    
        $correction_left = 0;
        $correction_right = 0;
        
        if ($is_auto) {
            // Mode AUTO: hitung semua trigger yang terbaca
            foreach ($batch_data as $record) {
                if ($record[2] > 0) $correction_left++;  // action_left > 0
                if ($record[3] > 0) $correction_right++; // action_right > 0
            }
            
            if ($this->option('v')) {
                $this->comment("→ AUTO MODE: Counted {$correction_left} left + {$correction_right} right = " . ($correction_left + $correction_right) . " total corrections");
            }
        } else {
            // Mode MANUAL: jangan hitung trigger
            // Koreksi dianggap 0 karena dilakukan manual oleh operator
            // (meskipun ada momentary is_correcting=true)
            $correction_left = 0;
            $correction_right = 0;
            
            if ($this->option('v')) {
                $this->comment("→ MANUAL MODE: Corrections set to 0 (operator-driven corrections not counted)");
            }
        }

        // Calculate correction rate percentage
        $total_corrections = $correction_left + $correction_right;
        $correction_rate = $batch_size > 0 ? (int) round(($total_corrections / ($batch_size * 2)) * 100) : 0;

        // Final determination: Auto only if high uptime AND has corrections
        //$is_auto = $is_auto_check && ($total_corrections > 0);

        // Determine is_auto based on correction uptime
        //$is_auto = $correction_uptime > 50;

        // Debug statistics if requested
        if ($this->option('d')) {
            $this->line('Statistics calculated:');
            $this->table(['Metric', 'Left', 'Right', 'Combined'], [
                ['MAE',
                    $stats['t_mae_left'] !== null ? round($stats['t_mae_left'], 2) : 'null',
                    $stats['t_mae_right'] !== null ? round($stats['t_mae_right'], 2) : 'null',
                    $stats['t_mae'] !== null ? round($stats['t_mae'], 2) : 'null',
                ],
                ['SSD', round($stats['t_ssd_left'], 2), round($stats['t_ssd_right'], 2), round($stats['t_ssd'], 2)],
                ['AVG', round($stats['t_avg_left'], 2), round($stats['t_avg_right'], 2), round($stats['t_avg'], 2)],
                ['Balance', '', '', round($stats['t_balance'], 2)],
                ['Correction Uptime', '', '', $correction_uptime . '%'],
                ['Correction Count', $correction_left, $correction_right, $correction_left + $correction_right],
                ['Correction Rate', '', '', $correction_rate . '%'],
                ['Is Auto', '', '', $is_auto ? 'Yes' : 'No'],
            ]);
        }

        // 🆕 SAVE WITH BOTH RECIPE REFERENCE AND ACTUAL INPUT
        try {
            $metric = new InsCtcMetric([
                'ins_ctc_machine_id' => $machine_id,
                'ins_rubber_batch_id' => null,
                'ins_ctc_recipe_id' => $batch_recipe_id,
                'is_auto' => $is_auto,
                
                // Performance metrics (calculated from actual operator input)
                't_mae_left' => $stats['t_mae_left'] !== null ? round($stats['t_mae_left'], 2) : null,
                't_mae_right' => $stats['t_mae_right'] !== null ? round($stats['t_mae_right'], 2) : null,
                't_mae' => $stats['t_mae'] !== null ? round($stats['t_mae'], 2) : null,
                't_ssd_left' => round($stats['t_ssd_left'], 2),
                't_ssd_right' => round($stats['t_ssd_right'], 2),
                't_ssd' => round($stats['t_ssd'], 2),
                't_avg_left' => round($stats['t_avg_left'], 2),
                't_avg_right' => round($stats['t_avg_right'], 2),
                't_avg' => round($stats['t_avg'], 2),
                't_balance' => round($stats['t_balance'], 2),
                
                // 🆕 Recipe reference (rekomendasi dari database)
                'recipe_std_min' => $recipe_std_min,
                'recipe_std_mid' => $recipe_std_mid,
                'recipe_std_max' => $recipe_std_max,
                
                // 🆕 Actual operator input (yang dipakai auto correction)
                'actual_std_min' => $actual_std_min,
                'actual_std_mid' => $actual_std_mid,
                'actual_std_max' => $actual_std_max,
                
                // Raw data and correction metrics
                'data' => $batch_data,
                'correction_uptime' => $correction_uptime,
                'correction_rate' => $correction_rate,
                'correction_left' => $correction_left,
                'correction_right' => $correction_right,
            ]);

            $metric->save();

            $this->info("✓ Batch saved: {$machine->name}, {$batch_size} measurements, Recipe {$batch_recipe_id}");

            // Debug saved data format if requested
            if ($this->option('d')) {
                $this->line('Sample data saved (first 2 records):');
                $sample_data = array_slice($batch_data, 0, 2);
                foreach ($sample_data as $i => $record) {
                    $this->line("  [{$i}] " . json_encode($record));
                }
            }

        } catch (\Exception $e) {
            $this->error("✗ Failed to save batch: {$e->getMessage()}");

            // Additional debugging for database errors
            if ($this->option('d')) {
                $this->line('Debug info:');
                $this->line("  Machine ID: {$machine_id}");
                $this->line("  Recipe ID: {$batch_recipe_id}");
                $this->line("  Batch size: {$batch_size}");
                $this->line('  Data type: ' . gettype($batch_data));
                $this->line('  Data sample: ' . json_encode(array_slice($batch_data, 0, 1)));
            }
        }
    }
    /**
     * Add measurement to batch buffer
     */
    public function addToBatch($machine_id, $metric)
    {
        $dt_now = Carbon::now()->format('Y-m-d H:i:s');

        // Detect sensor value changes (same logic as old system)
        $sensor_signature = $metric['sensor_left'].$metric['st_correct_left'].$metric['sensor_right'].$metric['st_correct_right'];

        if ($this->option('d')) {
            $this->line("DEBUG: Machine {$machine_id}");
            $this->line("  Sensor signature: {$sensor_signature}");
            $this->line('  Previous signature: '.($this->sensor_prev[$machine_id] ?? 'null'));
            $this->line("  Sensor values: L={$metric['sensor_left']}, R={$metric['sensor_right']}");
        }

        // Only process if sensor values changed AND at least one sensor is not zero
        if ($sensor_signature !== $this->sensor_prev[$machine_id] && ($metric['sensor_left'] || $metric['sensor_right'])) {

            // Convert sensor values
            $left_thickness = $this->convertToDecimal($metric['sensor_left']);
            $right_thickness = $this->convertToDecimal($metric['sensor_right']);

            // Convert std values
            $std_min = $this->convertToDecimal($metric['std_min']);
            $std_max = $this->convertToDecimal($metric['std_max']);
            $std_mid = $this->convertToDecimal($metric['std_mid']);

            // Calculate individual errors against Modbus std_mid
            $error_left = null;
            $error_right = null;

            if ($std_mid > 0 && $left_thickness !== 0) {
                $error_left = abs($left_thickness - $std_mid);
            }

            if ($std_mid > 0 && $right_thickness !== 0) {
                $error_right = abs($right_thickness - $std_mid);
            }

            // Determine actions (same logic as old system)
            $action_left = 0;
            $action_right = 0;

            $st_cl = $metric['st_correct_left'];
            $st_cr = $metric['st_correct_right'];

            // Check for correction actions
            if (($st_cl !== $this->st_cl_prev[$machine_id]) && $metric['is_correcting']) {
                $action_left = $this->getActionCode($metric['push_thin_left'], $metric['push_thick_left']);
                $this->st_cl_prev[$machine_id] = $st_cl;
            }

            if (($st_cr !== $this->st_cr_prev[$machine_id]) && $metric['is_correcting']) {
                $action_right = $this->getActionCode($metric['push_thin_right'], $metric['push_thick_right']);
                $this->st_cr_prev[$machine_id] = $st_cr;
            }

            // Store measurement data with calculated errors
            $measurement = [
                $dt_now,                // 0: timestamp
                $metric['is_correcting'], // 1: is_correcting
                $action_left,           // 2: action_left
                $action_right,          // 3: action_right
                $left_thickness,        // 4: left_thickness
                $right_thickness,       // 5: right_thickness
                $metric['recipe_id'],   // 6: recipe_id
                $std_min,               // 7: std_min
                $std_max,               // 8: std_max
                $std_mid,               // 9: std_mid
                $error_left,            // 10: error_left
                $error_right,           // 11: error_right
            ];

            // Initialize batch buffer if needed
            if (! isset($this->batch_buffers[$machine_id])) {
                $this->batch_buffers[$machine_id] = [];
            }

            // Add to batch buffer
            $this->batch_buffers[$machine_id][] = $measurement;
            $this->last_activity[$machine_id] = time();

            if ($this->option('d')) {
                $this->line("  Added measurement: L={$left_thickness}, R={$right_thickness}, std_mid={$std_mid}");
                $this->line("  Errors: L={$error_left}, R={$error_right}");
                $this->line('  Buffer size: '.count($this->batch_buffers[$machine_id]));
            }
        }

        // Update sensor signature for next iteration
        $this->sensor_prev[$machine_id] = $sensor_signature;
    }

    /**
     * Check for batch timeouts and process completed batches
     */
    public function checkBatchTimeouts()
    {
        $now = Carbon::now();

        if ($this->option('d')) {
            $this->line("\n=== TIMEOUT CHECK DEBUG ===");
            $this->line('Current time: '.$now->format('H:i:s'));
            $this->line("Batch timeout: {$this->batch_timeout} seconds");
        }

        foreach ($this->last_activity as $machine_id => $last_time) {
            $buffer_count = count($this->batch_buffers[$machine_id] ?? []);

            if ($this->option('d')) {
                $this->line("ID {$machine_id}: {$buffer_count} measurements, last activity: ".
                    ($last_time ? Carbon::parse($last_time)->format('H:i:s') : 'null'));
            }

            // Check if we have a valid last_time and non-empty buffer
            if ($last_time && ! empty($this->batch_buffers[$machine_id])) {

                // Ensure last_time is a Carbon instance
                if (! ($last_time instanceof Carbon)) {
                    $last_time = Carbon::parse($last_time);
                }

                // Calculate time difference
                $seconds_since_last = $last_time->diffInSeconds($now);

                if ($this->option('d')) {
                    $this->line('  → Last time: '.$last_time->format('Y-m-d H:i:s'));
                    $this->line("  → Seconds since last activity: {$seconds_since_last}");
                    $this->line('  → Should trigger? '.($seconds_since_last >= $this->batch_timeout ? 'YES' : 'NO'));
                }

                // Check if timeout has been reached
                if ($seconds_since_last >= $this->batch_timeout) {

                    if ($this->option('d')) {
                        $this->line('  → TIMEOUT TRIGGERED! Processing batch...');
                    }

                    // Process the completed batch
                    $this->processBatch($machine_id, $this->batch_buffers[$machine_id]);

                    // Clear batch buffer and reset activity
                    $this->batch_buffers[$machine_id] = [];
                    $this->last_activity[$machine_id] = null;

                    if ($this->option('d')) {
                        $this->line('  → Batch processed and buffer cleared');
                    }
                }
            } else {
                if ($this->option('d')) {
                    if (! $last_time) {
                        $this->line('  → No last_time set, skipping');
                    } elseif (empty($this->batch_buffers[$machine_id])) {
                        $this->line('  → Buffer is empty, skipping');
                    }
                }
            }
        }

        if ($this->option('d')) {
            $this->line("=== END TIMEOUT CHECK ===\n");
        }
    }

    /**
     * Initialize state variables for all machines
     */
    public function initializeState($machines)
    {
        foreach ($machines as $machine) {
            $this->batch_buffers[$machine->id] = [];
            $this->last_activity[$machine->id] = null;
            $this->sensor_prev[$machine->id] = null;
            $this->recipe_id_prev[$machine->id] = null;
            $this->st_cl_prev[$machine->id] = null;
            $this->st_cr_prev[$machine->id] = null;
        }
    }

    /**
     * Execute the console command
     */
    public function handle()
    {
        // Get all registered machines
        $machines = InsCtcMachine::all();
        $unit_id = 1;

        if ($machines->isEmpty()) {
            $this->error('✗ No machines found in database');

            return 1;
        }

        $this->info('✓ InsCtcPoll started - monitoring '.count($machines).' machines');
        $this->info("✓ Configuration: {$this->batch_timeout}s timeout, {$this->minimum_measurements} min measurements");

        if ($this->option('v')) {
            $this->comment('Machines:');
            foreach ($machines as $machine) {
                $this->comment("  → {$machine->name} ({$machine->ip_address})");
            }
        }

        // Initialize state
        $this->initializeState($machines);

        // Main polling loop
        while (true) {
            $dt_now = Carbon::now()->format('Y-m-d H:i:s');

            // Poll all machines
            foreach ($machines as $machine) {

                if ($this->option('v')) {
                    $this->comment("→ Polling {$machine->name} ({$machine->ip_address})");
                }

                try {
                    // Build Modbus requests (same as old system)
                    $fc2 = ReadCoilsBuilder::newReadInputDiscretes('tcp://'.$machine->ip_address.':502', $unit_id)
                        ->coil(0, 'is_correcting')
                        ->build();

                    $fc3 = ReadRegistersBuilder::newReadHoldingRegisters('tcp://'.$machine->ip_address.':502', $unit_id)
                        ->int16(0, 'sensor_left')
                        ->int16(1, 'sensor_right')
                        // ->int16(2, 'unknown') // missing register
                        ->int16(3, 'recipe_id')
                        ->int16(4, 'push_thin_left')
                        ->int16(5, 'push_thick_left')
                        ->int16(6, 'push_thin_right')
                        ->int16(7, 'push_thick_right')
                        ->int16(8, 'st_correct_left')
                        ->int16(9, 'st_correct_right')
                        ->int16(10, 'std_min')           // NEW
                        ->int16(11, 'std_max')           // NEW
                        ->int16(12, 'std_mid')           // NEW

                        ->int16(13, 'hmi_model')         // NEW
                        ->int16(14, 'hmi_components')           // NEW
                        ->build();

                    // Execute Modbus requests
                    $fc2_response = (new NonBlockingClient(['readTimeoutSec' => 2]))->sendRequests($fc2);
                    $fc3_response = (new NonBlockingClient(['readTimeoutSec' => 2]))->sendRequests($fc3);

                    $fc2_data = $fc2_response->getData();
                    $fc3_data = $fc3_response->getData();

                    // Prepare metric data
                    $metric = [
                        'sensor_left' => $fc3_data['sensor_left'],
                        'sensor_right' => $fc3_data['sensor_right'],
                        'recipe_id' => $fc3_data['recipe_id'],
                        'is_correcting' => $fc2_data['is_correcting'],
                        'push_thin_left' => $fc3_data['push_thin_left'],
                        'push_thick_left' => $fc3_data['push_thick_left'],
                        'push_thin_right' => $fc3_data['push_thin_right'],
                        'push_thick_right' => $fc3_data['push_thick_right'],
                        'st_correct_left' => $fc3_data['st_correct_left'],
                        'st_correct_right' => $fc3_data['st_correct_right'],
                        'std_min' => $fc3_data['std_min'],     // NEW
                        'std_max' => $fc3_data['std_max'],     // NEW
                        'std_mid' => $fc3_data['std_mid'],     // NEW
                    ];

                    // Update cache untuk grafik real-time
                    $cacheKey = "ctc_realtime_{$machine->id}";
                    Cache::put($cacheKey, [
                        'sensor_left' => $this->convertToDecimal($metric['sensor_left']),
                        'sensor_right' => $this->convertToDecimal($metric['sensor_right']),
                        'recipe_id' => $metric['recipe_id'],
                        'is_correcting' => $metric['is_correcting'],
                        'std_min' => $this->convertToDecimal($metric['std_min']),
                        'std_max' => $this->convertToDecimal($metric['std_max']),
                        'std_mid' => $this->convertToDecimal($metric['std_mid']),
                        'timestamp' => now()->toISOString(),
                    ], 5); // Cache expires after 5 seconds
                    
                    // Verbose output untuk cache update
                    if ($this->option('v')) {
                        $this->line("  ✓ Cache updated: Machine {$machine->id} | L:{$this->convertToDecimal($metric['sensor_left'])} R:{$this->convertToDecimal($metric['sensor_right'])}");
                    }  // ← TAMBAH CLOSING BRACE INI!

                    // Debug output (tabel detail)
                    if ($this->option('d')) {
                        $this->line('');
                        $this->line("Raw data from {$machine->name}");
                        $this->line("IP address {$machine->ip_address}:");
                        $this->table(['Field', 'Value'], [
                            ['Sensor Left', $metric['sensor_left']],
                            ['Sensor Right', $metric['sensor_right']],
                            ['Recipe ID', $metric['recipe_id']],
                            ['Is Correcting', $metric['is_correcting'] ? 'Yes' : 'No'],
                            ['Push Thin L', $metric['push_thin_left']],
                            ['Push Thick L', $metric['push_thick_left']],
                            ['Push Thin R', $metric['push_thin_right']],
                            ['Push Thick R', $metric['push_thick_right']],
                            ['Std Min', $metric['std_min']],
                            ['Std Max', $metric['std_max']],
                            ['Std Mid', $metric['std_mid']],
                        ]);
                    }

                    // Add to batch buffer untuk historical data
                    $this->addToBatch($machine->id, $metric);

                } catch (\Throwable $th) {
                    $this->error("✗ Error polling {$machine->name} ({$machine->ip_address}): ".$th->getMessage());
                }
            }

            // Check for batch timeouts and process completed batches
            $this->checkBatchTimeouts();

            // Sleep before next iteration
            sleep(1);
        }
    }
}