<?php

use App\Models\InsOmvCapture;
use App\Models\InsOmvMetric;
use App\Models\InsOmvRecipe;
use App\Models\InsRubberBatch;
use App\Models\InvTag;
use App\Models\User;
use App\Models\InsCtcRecipe;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::get('/ins-rubber-batches/recents', function () {
    $batches = InsRubberBatch::latest('updated_at')->limit(19)->get();

    return response()->json($batches);
});

Route::get('/ins-rubber-batches/{code}', function ($code) {
    $batch = InsRubberBatch::where('code', $code)->latest('updated_at')->first();

    return response()->json($batch);
});

Route::get('/inv-tags', function (Request $request) {
    $q = trim($request['q']);
    $hints = [];
    if ($q) {
        $hints = InvTag::where('name', 'LIKE', '%'.$q.'%')
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->pluck('name')
            ->toArray();
    }

    return response()->json($hints);
});

Route::get('/time', function () {
    $currentTime = Carbon::now('UTC');

    return response()->json([
        'timestamp' => $currentTime->timestamp,
        'formatted' => $currentTime->toIso8601String(),
    ]);
});

Route::get('/omv-recipes', function () {
    $recipes = InsOmvRecipe::all()->map(function ($recipe) {
        // Parse the steps JSON field
        $steps = json_decode($recipe->steps);

        // Parse the capture_points JSON field
        $capture_points = json_decode($recipe->capture_points);

        return [
            'id' => $recipe->id,
            'type' => $recipe->type,
            'name' => $recipe->name,
            'capture_points' => $capture_points,
            'steps' => $steps,
        ];
    });

    return response()->json($recipes);
});

Route::post('/omv-metric', function (Request $request) {
    $validator = Validator::make($request->all(), [
        'recipe_id' => 'required|exists:ins_omv_recipes,id',
        'code' => 'nullable|string|max:20',
        'model' => 'nullable|string|max:30',
        'color' => 'nullable|string|max:20',
        'mcs' => 'nullable|string|max:10',
        'line' => 'required|integer|min:1|max:99',
        'team' => 'required|in:A,B,C',
        'user_1_emp_id' => 'required|exists:users,emp_id',
        'user_2_emp_id' => 'nullable|string',
        'eval' => 'required|in:too_soon,on_time,too_late,on_time_manual',
        'start_at' => 'required|date_format:Y-m-d H:i:s',
        'end_at' => 'required|date_format:Y-m-d H:i:s',
        'images' => 'nullable|array',
        'images.*.step_index' => 'required|integer',
        'images.*.taken_at' => 'required|numeric',
        'images.*.image' => 'required|string',
        'amps' => 'nullable|array',
        'amps.*.taken_at' => 'required|numeric',
        'amps.*.value' => 'required|integer',
        'composition' => 'required|size:7|array',
        'composition.*' => 'nullable|min:0|max:5000|numeric',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 'invalid',
            'msg' => $validator->errors()->all(),
        ], 400);
    }

    $validated = $validator->validated();
    $user1 = User::where('emp_id', $validated['user_1_emp_id'])->first();
    $user2 = User::where('emp_id', $validated['user_2_emp_id'])->first();

    $errors = [];

    if (! $user1) {
        $errors[] = "The emp_id '{$validated['user_1_emp_id']}' on user_1_emp_id does not exist.";
    }

    $isExists = InsOmvMetric::where('line', $validated['line'])->where('start_at', $validated['start_at'])->exists();

    if ($isExists) {
        $errors[] = "A metric for line '{$validated['line']}' already exists at '{$validated['start_at']}'.";
    }

    if (! empty($errors)) {
        return response()->json([
            'status' => 'invalid',
            'msg' => $errors,
        ], 400);
    }

    $code = strtoupper(trim($validated['code']));
    $model = strtoupper(trim($validated['model']));
    $color = strtoupper(trim($validated['color']));
    $mcs = strtoupper(trim($validated['mcs']));

    $batch = null;
    if ($code) {
        $batch = InsRubberBatch::updateOrCreate(
            [
                'code' => $code,
            ],
            [
                'model' => $model,
                'color' => $color,
                'mcs' => $mcs,
                'composition' => json_encode($validated['composition']),
            ]);
    }

    $amps = $validated['amps'];
    $filteredAmps = [];

    // limit the array if it's too big then just return empty filteredamps altogether
    if (count($validated['amps']) < 10000) {
        $maxTakenAt = null;
        // Traverse the array from the last element to the first
        for ($i = count($amps) - 1; $i >= 0; $i--) {
            $current = $amps[$i];
            if ($maxTakenAt === null || $current['taken_at'] <= $maxTakenAt) {
                $filteredAmps[] = $current;
                $maxTakenAt = $current['taken_at'];
            } else {
                // We found an increase in `taken_at`, discard everything before this point
                break;
            }
        }
    }

    $amps = array_reverse($filteredAmps);

    $voltage = 380; // Voltase
    $kwhUsage = 0;  // Total energi, diinisiasi dengan 0
    $powerFactor = 0.85;
    $calibrationFactor = 0.8;

    for ($i = 1; $i < count($amps); $i++) {
        // Hitung rerata arus per interval
        $avgCurrent = ($amps[$i]['value'] + $amps[$i - 1]['value']) / 2;

        // Hitung durasi interval lalu konversi dari detik ke jam
        $timeInterval = ($amps[$i]['taken_at'] - $amps[$i - 1]['taken_at']) / 3600;

        // Hitung energi per interval
        $energy = (sqrt(3) * $avgCurrent * $voltage * $timeInterval * $powerFactor * $calibrationFactor) / 1000;
        $kwhUsage += $energy; // Jumlahkan total energi semua interval
    }

    $omvMetric = new InsOmvMetric;
    $omvMetric->ins_omv_recipe_id = $validated['recipe_id'];
    $omvMetric->line = $validated['line'];
    $omvMetric->team = $validated['team'];
    $omvMetric->user_1_id = $user1->id;
    $omvMetric->user_2_id = $user2->id ?? null;
    $omvMetric->eval = strtolower($validated['eval']); // converting eval to lowercase
    $omvMetric->start_at = $validated['start_at'];
    $omvMetric->end_at = $validated['end_at'];
    $omvMetric->data = json_encode(['amps' => $amps]);
    $omvMetric->ins_rubber_batch_id = $batch ? $batch->id : null;
    $omvMetric->kwh_usage = $kwhUsage;
    $omvMetric->save();

    $captureMessages = [];

    foreach ($validated['images'] as $index => $image) {
        try {
            if (! isset($image['image'])) {
                throw new Exception('Image data is missing.');
            }

            $parts = explode(',', $image['image']);
            if (count($parts) != 2) {
                throw new Exception('Invalid image format.');
            }

            $imageData = base64_decode($parts[1], true);
            if ($imageData === false) {
                throw new Exception('Invalid base64 encoding.');
            }

            $imageInfo = getimagesizefromstring($imageData);
            if ($imageInfo === false) {
                throw new Exception('Invalid image data.');
            }

            $mimeType = $imageInfo['mime'];
            $extension = explode('/', $mimeType)[1] ?? 'png'; // Default to png if mime type is unexpected

            $fileName = sprintf(
                '$s_%s.%s',
                $omvMetric->id,
                Str::random(6),
                $extension
            );

            if (! Storage::put('/public/omv-captures/'.$fileName, $imageData)) {
                throw new Exception('Failed to save image file.');
            }

            $omvCapture = new InsOmvCapture;
            $omvCapture->ins_omv_metric_id = $omvMetric->id;
            $omvCapture->file_name = $fileName;
            $omvCapture->taken_at = $image['taken_at'];
            $omvCapture->save();

        } catch (Exception $e) {
            $captureMessages[] = "Error saving capture {$index}: ".$e->getMessage();
            // You might want to log the full exception details here
            // Log::error('Image capture error: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    $responseMessage = 'OMV Metric saved successfully.';
    if (! empty($captureMessages)) {
        $responseMessage .= ' However, there were issues with some captures: '.implode(', ', $captureMessages);
    }

    return response()->json([
        'status' => 'valid',
        'msg' => $responseMessage,
    ], 200);
});

// ========================================
// CTC RECIPES API - untuk HMI Weintek
// ========================================

// ENDPOINT 1: Get List Model (untuk Page 1 HMI)
Route::get('/ctc-recipes/models', function () {
    $models = InsCtcRecipe::where('is_active', true)
        ->distinct()
        ->orderBy('name')
        ->pluck('name')
        ->values()
        ->toArray();

    return response()->json([
        'status' => 'success',
        'data' => $models
    ]);
});

// ENDPOINT 2: Get Components & Recommendations by Model (untuk Page 2 HMI)
Route::get('/ctc-recipes/details/{modelName}', function ($modelName) {
    // Decode URL encoding jika ada spasi (AF1%20GS -> AF1 GS)
    $modelName = urldecode($modelName);
    
    // Get all recipes for this model yang aktif
    $recipes = InsCtcRecipe::where('name', $modelName)
        ->where('is_active', true)
        ->orderBy('component_model')
        ->orderBy('og_rs')
        ->get();

    if ($recipes->isEmpty()) {
        return response()->json([
            'status' => 'error',
            'message' => 'Model tidak ditemukan'
        ], 404);
    }

    // Group by component untuk list components
    $components = $recipes->pluck('component_model')
        ->unique()
        ->filter() // Remove null values
        ->values()
        ->toArray();

    // Format detail recommendations
    $recommendations = $recipes->map(function ($recipe) {
        return [
            'id' => $recipe->id,
            'component_model' => $recipe->component_model ?? 'RESEP',
            'og_rs' => $recipe->og_rs,
            'std_min' => (float) $recipe->std_min,
            'std_max' => (float) $recipe->std_max,
            'std_mid' => (float) $recipe->std_mid,
            'scale' => $recipe->scale ? (float) $recipe->scale : null,
            'pfc_min' => $recipe->pfc_min ? (float) $recipe->pfc_min : null,
            'pfc_max' => $recipe->pfc_max ? (float) $recipe->pfc_max : null,
        ];
    });

    return response()->json([
        'status' => 'success',
        'data' => [
            'model' => $modelName,
            'components' => $components,
            'recommendations' => $recommendations
        ]
    ]);
});

// ENDPOINT 3 (OPTIONAL): Get Specific Recommendation
Route::get('/ctc-recipes/recommendation', function (Request $request) {
    $validator = Validator::make($request->all(), [
        'model' => 'required|string',
        'component' => 'nullable|string',
        'og_rs' => 'nullable|string',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 'error',
            'message' => $validator->errors()->first()
        ], 400);
    }

    $query = InsCtcRecipe::where('name', $request->model)
        ->where('is_active', true);

    if ($request->component) {
        $query->where('component_model', $request->component);
    }

    if ($request->og_rs) {
        $query->where('og_rs', $request->og_rs);
    }

    $recipe = $query->first();

    if (!$recipe) {
        return response()->json([
            'status' => 'error',
            'message' => 'Resep tidak ditemukan'
        ], 404);
    }

    return response()->json([
        'status' => 'success',
        'data' => [
            'id' => $recipe->id,
            'model' => $recipe->name,
            'component_model' => $recipe->component_model ?? 'RESEP',
            'og_rs' => $recipe->og_rs,
            'std_min' => (float) $recipe->std_min,
            'std_max' => (float) $recipe->std_max,
            'std_mid' => (float) $recipe->std_mid,
            'scale' => $recipe->scale ? (float) $recipe->scale : null,
            'pfc_min' => $recipe->pfc_min ? (float) $recipe->pfc_min : null,
            'pfc_max' => $recipe->pfc_max ? (float) $recipe->pfc_max : null,
        ]
    ]);
});

// ENDPOINT 4: Loadcell upload
Route::post('/loadcell-upload', function (Request $request) {
    $validator = Validator::make($request->all(), [
        'file' => 'required|file|mimes:json,txt|max:10240', // Max 10MB
        'operator_name' => 'nullable|string|max:50',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 'error',
            'message' => $validator->errors()->first()
        ], 400);
    }

    try {
        // Read JSON file
        $file = $request->file('file');
        $jsonContent = file_get_contents($file->getRealPath());
        $data = json_decode($jsonContent, true);
        $operatorName = $request->input('operator_name', 'Operator');

        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid JSON format: ' . json_last_error_msg()
            ], 400);
        }

        // Validate required structure
        if (!isset($data['metadata']) || !isset($data['sensors'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid data structure. Required: metadata and sensors fields.'
            ], 400);
        }

        $metadata = $data['metadata'];
        
        // Validate required metadata fields
        $requiredFields = ['plant', 'location', 'line', 'machine', 'position'];
        foreach ($requiredFields as $field) {
            if (!isset($metadata[$field])) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Missing required metadata field: {$field}"
                ], 400);
            }
        }

        // Calculate duration from metadata
        $duration = 0;
        if (isset($metadata['collection_start']) && isset($metadata['collection_end'])) {
            $start = Carbon::parse($metadata['collection_start']);
            $end = Carbon::parse($metadata['collection_end']);
            $duration = $end->diffInSeconds($start);
        }

        // Prepare loadcell data record
        $loadcellData = new \App\Models\InsDwpLoadcell();
        $loadcellData->machine_name = $metadata['machine'] ?? null;
        $loadcellData->plant = $metadata['plant'] ?? null;
        $loadcellData->line = $metadata['line'] ?? null;
        $loadcellData->duration = $duration;
        $loadcellData->position = $metadata['position'] ?? null;
        $loadcellData->result = "std"; // Set based on your evaluation logic
        $loadcellData->operator = $operatorName; // Set if available in metadata
        $loadcellData->recorded_at = isset($metadata['timestamp']) 
            ? Carbon::parse($metadata['timestamp']) 
            : Carbon::now();
        
        // Store raw sensor data as JSON
        $loadcellData->loadcell_data = json_encode([
            'metadata' => $metadata,
        ]);

        $loadcellData->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Loadcell data uploaded successfully.',
            'data' => [
                'id' => $loadcellData->id,
                'plant' => $loadcellData->plant,
                'line' => $loadcellData->line,
                'machine' => $loadcellData->machine_name,
                'position' => $loadcellData->position,
                'recorded_at' => $loadcellData->recorded_at,
                'total_cycles' => $metadata['total_cycles'] ?? 0,
                'total_data_points' => $metadata['total_data_points'] ?? 0,
                'max_pressure' => $metadata['max_peak_pressure'] ?? 0,
            ]
        ], 201);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Failed to process loadcell data: ' . $e->getMessage()
        ], 500);
    }
});

// ENDPOINT 5: Loadcell data direct JSON POST (alternative to file upload)
Route::post('/api/loadcell-data', function (Request $request) {
    try {
        $data = $request->all();

        // Validate required structure
        if (!isset($data['metadata']) || !isset($data['sensors'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid data structure. Required: metadata and sensors fields.'
            ], 400);
        }

        $metadata = $data['metadata'];
        
        // Validate required metadata fields
        $requiredFields = ['plant', 'line', 'machine', 'position'];
        foreach ($requiredFields as $field) {
            if (!isset($metadata[$field])) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Missing required metadata field: {$field}"
                ], 400);
            }
        }

        // Calculate duration from metadata
        $duration = 0;
        if (isset($metadata['collection_start']) && isset($metadata['collection_end'])) {
            $start = Carbon::parse($metadata['collection_start']);
            $end = Carbon::parse($metadata['collection_end']);
            $duration = $end->diffInSeconds($start);
        }

        // Prepare loadcell data record
        $loadcellData = new \App\Models\InsDwpLoadcell();
        $loadcellData->machine_name = $metadata['machine'] ?? null;
        $loadcellData->plant = $metadata['plant'] ?? null;
        $loadcellData->line = $metadata['line'] ?? null;
        $loadcellData->duration = $duration;
        $loadcellData->position = $metadata['position'] ?? null;
        $loadcellData->range_std = $metadata['max_peak_pressure'] ?? null;
        $loadcellData->toe_heel = null; // Set based on your logic
        $loadcellData->side = $metadata['position'] ?? null;
        $loadcellData->result = null; // Set based on your evaluation logic
        $loadcellData->operator = "Operator"; // Set if available in metadata
        $loadcellData->recorded_at = isset($metadata['timestamp']) 
            ? Carbon::parse($metadata['timestamp']) 
            : Carbon::now();
        
        // Store raw sensor data as JSON
        $loadcellData->loadcell_data = json_encode([
            'metadata' => $metadata,
        ]);

        $loadcellData->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Loadcell data saved successfully.',
            'data' => [
                'id' => $loadcellData->id,
                'plant' => $loadcellData->plant,
                'line' => $loadcellData->line,
                'machine' => $loadcellData->machine,
                'position' => $loadcellData->position,
                'recorded_at' => $loadcellData->recorded_at,
                'total_cycles' => $metadata['total_cycles'] ?? 0,
                'total_data_points' => $metadata['total_data_points'] ?? 0,
                'max_pressure' => $metadata['max_peak_pressure'] ?? 0,
            ]
        ], 201);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Failed to process loadcell data: ' . $e->getMessage()
        ], 500);
    }
});
