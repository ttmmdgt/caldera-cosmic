<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Auth;

use App\Models\InsRubberBatch;
use App\Models\InsRdcMachine;
use App\Models\InsRdcTest;

use PhpOffice\PhpSpreadsheet\IOFactory;

new class extends Component {
    use WithFileUploads;

    public $file;

    public array $machines = [];

    public array $batch = [
        "id" => "",
        "code" => "",
        "code_alt" => "",
        "model" => "",
        "color" => "",
        "mcs" => "",
        "shift" => "",
    ];

    public bool $update_batch = true;

    public array $test = [
        "ins_rdc_machine_id" => 0,
        "s_max_low" => "",
        "s_max_high" => "",
        "s_min_low" => "",
        "s_min_high" => "",
        "tc10_low" => "",
        "tc10_high" => "",
        "tc50_low" => "",
        "tc50_high" => "",
        "tc90_low" => "",
        "tc90_high" => "",
        "type" => "",
        "s_max" => "",
        "s_min" => "",
        "tc10" => "",
        "tc50" => "",
        "tc90" => "",
        "eval" => "",
        "shift" => "",
        "material_type" => "",
        "status_test" => "",
    ];

    public array $shoe_models = [];

    public function mount()
    {
        $this->batch["code"] = __("Kode batch");
        $this->machines = InsRdcMachine::all()
            ->where("is_active", true)
            ->toArray();
    }

    #[On("test-create")]
    public function loadBatch($id)
    {
        $this->customReset();

        $batch = InsRubberBatch::find($id);

        if ($batch) {
            $this->batch["id"] = $batch->id;
            $this->batch["code"] = $batch->code;
            $this->batch["code_alt"] = $batch->code_alt;
            $this->batch["model"] = $batch->model;
            $this->batch["color"] = $batch->color;
            $this->batch["mcs"] = $batch->mcs;
            $this->batch["shift"] = $batch->shift;
        } else {
            $this->handleNotFound();
        }
    }

    private function customReset()
    {
        $this->resetErrorBag();
        $this->reset(["file", "batch", "test"]);
        $this->batch["code"] = __("Kode batch");
    }

    public function customResetBatch()
    {
        $id = $this->batch["id"];
        $this->customReset();
        $this->loadBatch($id);
    }

    public function handleNotFound()
    {
        $this->js('$dispatch("close")');
        $this->js('toast("' . __("Tidak ditemukan") . '", { type: "danger" })');
        $this->dispatch("updated");
    }

    public function rules()
    {
        return [
            "batch.code_alt" => "nullable|string|max:50",
            "batch.model" => "nullable|string|max:30",
            "batch.color" => "nullable|string|max:20",
            "batch.mcs" => "nullable|string|max:10",

            "test.ins_rdc_machine_id" => "required|exists:ins_rdc_machines,id",

            "test.s_min_low" => "required|numeric|gte:0|lte:99",
            "test.s_min_high" => "required|numeric|gte:0|lte:99",
            "test.s_max_low" => "required|numeric|gte:0|lte:99",
            "test.s_max_high" => "required|numeric|gte:0|lte:99",

            "test.tc10_low" => "required|numeric|gte:0|lte:999",
            "test.tc10_high" => "required|numeric|gte:0|lte:999",
            "test.tc50_low" => "required|numeric|gte:0|lte:999",
            "test.tc50_high" => "required|numeric|gte:0|lte:999",
            "test.tc90_low" => "required|numeric|gte:0|lte:999",
            "test.tc90_high" => "required|numeric|gte:0|lte:999",

            "test.type" => "required|in:-,slow,fast",
            "test.s_max" => "required|numeric|gte:0|lte:99",
            "test.s_min" => "required|numeric|gte:0|lte:99",
            "test.tc10" => "required|numeric|gte:0|lte:999",
            "test.tc50" => "required|numeric|gte:0|lte:999",
            "test.tc90" => "required|numeric|gte:0|lte:999",
            "test.eval" => "required|in:pass,fail",
            "test.shift" => "required|in:A,B,C",
            "test.material_type" => "required|string",
            "test.status_test" => "required|in:new,retest,skip",
        ];
    }

    public function updatedFile()
    {
        // Validate file first
        $this->validate([
            "file" => "file|max:1024",
        ]);

        // Get the selected machine
        $machine = InsRdcMachine::find($this->test["ins_rdc_machine_id"]);
        if (! $machine instanceof InsRdcMachine) {
            $this->js('toast("' . __("Pilih mesin terlebih dahulu") . '", { type: "danger" })');
            $this->reset(["file"]);
            return;
        }

        // Validate file type against machine type
        $mimeType = $this->file->getMimeType();
        $isValidFile = $this->validateFileForMachine($mimeType, $machine->type);

        if (! $isValidFile) {
            $expectedTypes = $machine->type === "excel" ? "Excel (.xls, .xlsx)" : "Text (.txt)";
            $this->js('toast("' . __("File tidak sesuai dengan tipe mesin. Diharapkan: ") . $expectedTypes . '", { type: "danger" })');
            $this->reset(["file"]);
            return;
        }

        // Process the file based on machine type
        try {
            if ($machine->type === "excel") {
                $this->extractDataExcel($machine);
            } else {
                $this->extractDataText($machine);
            }
        } catch (\Exception $e) {
            $this->js('toast("' . __("Gagal memproses file: ") . $e->getMessage() . '", { type: "danger" })');
        }

        $this->reset(["file"]);
    }

    private function validateFileForMachine(string $mimeType, string $machineType): bool
    {
        return match ($machineType) {
            "excel" => in_array($mimeType, ["application/vnd.ms-excel", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"]),
            "txt" => $mimeType === "text/plain",
            default => false,
        };
    }

    /**
     * Updated extraction for Excel files with new bounds support
     */
    private function extractDataExcel(InsRdcMachine $machine)
    {
        $config = $machine->cells ?? [];
        if (empty($config)) {
            throw new \Exception("Mesin tidak memiliki konfigurasi");
        }

        $path = $this->file->getRealPath();
        $spreadsheet = IOFactory::load($path);
        $worksheet = $spreadsheet->getActiveSheet();

        $extractedData = [];

        foreach ($config as $fieldConfig) {
            if (! isset($fieldConfig["field"])) {
                continue;
            }

            $field = $fieldConfig["field"];

            try {
                $value = null;

                // Handle new type-based configuration
                if (isset($fieldConfig["type"])) {
                    switch ($fieldConfig["type"]) {
                        case "static":
                            $value = $this->extractStaticValue($worksheet, $fieldConfig);
                            break;
                        case "dynamic":
                            $value = $this->extractDynamicValue($worksheet, $fieldConfig);
                            break;
                        default:
                            continue 2;
                    }
                }
                // Handle legacy configuration for backward compatibility
                elseif (isset($fieldConfig["address"])) {
                    $value = $this->extractStaticValue($worksheet, ["address" => $fieldConfig["address"]]);
                } else {
                    continue;
                }

                if ($value !== null) {
                    $extractedData[$field] = $this->processFieldValue($field, $value);
                }
            } catch (\Exception $e) {
                // Log error but continue processing other fields
                $this->js('console.log("Error extracting ' . $field . ": " . addslashes($e->getMessage()) . '")');
                continue;
            }
        }

        $this->applyExtractedData($extractedData);
    }

    /**
     * Extract value using static cell address
     */
    private function extractStaticValue($worksheet, array $config)
    {
        if (! isset($config["address"])) {
            return null;
        }

        $address = strtoupper(trim($config["address"]));
        return $worksheet->getCell($address)->getValue();
    }

    /**
     * Updated extraction for Text files with new bounds support
     */
    private function extractDataText(InsRdcMachine $machine)
    {
        $config = $machine->cells ?? [];
        if (empty($config)) {
            throw new \Exception("Mesin tidak memiliki konfigurasi");
        }

        $content = file_get_contents($this->file->getRealPath());
        $lines = explode("\n", $content);

        $extractedData = [];

        foreach ($config as $fieldConfig) {
            if (! isset($fieldConfig["field"])) {
                continue;
            }

            $field = $fieldConfig["field"];

            try {
                $value = null;
                $pattern = null;

                // Handle new type-based configuration
                if (isset($fieldConfig["type"]) && $fieldConfig["type"] === "pattern") {
                    $pattern = $fieldConfig["pattern"] ?? null;
                }
                // Handle legacy configuration for backward compatibility
                elseif (isset($fieldConfig["pattern"])) {
                    $pattern = $fieldConfig["pattern"];
                }

                if ($pattern) {
                    $value = $this->extractValueFromText($lines, $pattern);
                }

                if ($value !== null) {
                    $extractedData[$field] = $this->processFieldValue($field, $value);
                }
            } catch (\Exception $e) {
                // Log error but continue processing other fields
                $this->js('console.log("Error extracting ' . $field . ": " . addslashes($e->getMessage()) . '")');
                continue;
            }
        }

        $this->applyExtractedData($extractedData);
    }

    /**
     * Extract value using dynamic intersection search
     */
    private function extractDynamicValue($worksheet, array $config)
    {
        if (! isset($config["row_search"]) || ! isset($config["column_search"])) {
            return null;
        }

        $rowSearch = InsRdcMachine::normalizeSearchTerm($config["row_search"]);
        $columnSearch = InsRdcMachine::normalizeSearchTerm($config["column_search"]);
        $rowOffset = $config["row_offset"] ?? 0;
        $columnOffset = $config["column_offset"] ?? 0;

        // Find row containing the search term (limit to first 30 rows)
        $targetRow = $this->findRowBySearchTerm($worksheet, $rowSearch, 30);
        if ($targetRow === null) {
            throw new \Exception("Row search term '{$config["row_search"]}' not found");
        }

        // Find column containing the search term (limit to first 15 columns)
        $targetColumn = $this->findColumnBySearchTerm($worksheet, $columnSearch, 15);
        if ($targetColumn === null) {
            throw new \Exception("Column search term '{$config["column_search"]}' not found");
        }

        // Apply offsets
        $finalRow = $targetRow + $rowOffset;
        $finalColumn = $this->columnLetterToNumber($targetColumn) + $columnOffset;

        // Validate bounds
        if ($finalRow < 1 || $finalColumn < 1) {
            throw new \Exception("Intersection with offsets is out of bounds");
        }

        $finalColumnLetter = $this->numberToColumnLetter($finalColumn);
        $intersectionAddress = $finalColumnLetter . $finalRow;

        return $worksheet->getCell($intersectionAddress)->getValue();
    }

    /**
     * Find row number containing search term
     */
    private function findRowBySearchTerm($worksheet, string $normalizedSearchTerm, int $maxRows): ?int
    {
        $highestColumn = $worksheet->getHighestColumn();
        $columnCount = min(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn), 15);

        for ($row = 1; $row <= $maxRows; $row++) {
            for ($col = 1; $col <= $columnCount; $col++) {
                $columnLetter = $this->numberToColumnLetter($col);
                $cellValue = $worksheet->getCell($columnLetter . $row)->getValue();

                if ($cellValue !== null) {
                    $normalizedCellValue = InsRdcMachine::normalizeSearchTerm((string) $cellValue);
                    if ($normalizedCellValue === $normalizedSearchTerm) {
                        return $row;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Find column letter containing search term
     */
    private function findColumnBySearchTerm($worksheet, string $normalizedSearchTerm, int $maxColumns): ?string
    {
        for ($col = 1; $col <= $maxColumns; $col++) {
            $columnLetter = $this->numberToColumnLetter($col);

            // Search in first 30 rows of this column
            for ($row = 1; $row <= 30; $row++) {
                $cellValue = $worksheet->getCell($columnLetter . $row)->getValue();

                if ($cellValue !== null) {
                    $normalizedCellValue = InsRdcMachine::normalizeSearchTerm((string) $cellValue);
                    if ($normalizedCellValue === $normalizedSearchTerm) {
                        return $columnLetter;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Convert column letter to number (A=1, B=2, etc.)
     */
    private function columnLetterToNumber(string $columnLetter): int
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnLetter);
    }

    /**
     * Convert number to column letter (1=A, 2=B, etc.)
     */
    private function numberToColumnLetter(int $columnNumber): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnNumber);
    }

    /**
     * Enhanced field value processing with proper bounds handling
     */
    private function processFieldValue(string $field, $value): mixed
    {
        $value = trim((string) $value);

        // Handle bounds fields (s_max_bounds, s_min_bounds, tc10_bounds, etc.)
        if (str_ends_with($field, "_bounds")) {
            return $this->parseBoundsRange($value);
        }

        // Handle regular fields
        return match ($field) {
            "mcs" => $this->find3Digit($value),
            "color", "code_alt", "model" => $this->safeString($value),
            // Direct measurement fields
            "s_max", "s_min", "tc10", "tc50", "tc90" => $this->safeFloat($value),
            "eval" => $this->processEvalValue($value),
            default => $value,
        };
    }

    /**
     * Parse a bounds range string like "20.5-30.8" into low/high values
     */
    private function parseBoundsRange(string $range): array
    {
        if (empty($range) || ! str_contains($range, "-")) {
            return ["low" => 0, "high" => 0];
        }

        // Handle different range formats: "20.5-30.8", "20.5 - 30.8", etc.
        $range = preg_replace("/\s+/", "", $range); // Remove all spaces
        [$part1, $part2] = explode("-", $range, 2);

        $value1 = $this->safeFloat($part1);
        $value2 = $this->safeFloat($part2);

        $lower = min($value1, $value2);
        $higher = max($value1, $value2);

        return [
            "low" => $lower,
            "high" => $higher,
        ];
    }

    /**
     * Process bounds value and return array with low/high values
     */
    private function processBoundsValue(string $value): array
    {
        if (empty($value) || ! str_contains($value, "-")) {
            return ["low" => 0, "high" => 0];
        }

        [$part1, $part2] = explode("-", $value, 2);

        $value1 = $this->safeFloat($part1);
        $value2 = $this->safeFloat($part2);

        $lower = min($value1, $value2);
        $higher = max($value1, $value2);

        return [
            "low" => $lower,
            "high" => $higher,
        ];
    }

    /**
     * Enhanced data application with proper bounds field mapping
     */
    private function applyExtractedData(array $extractedData)
    {
        foreach ($extractedData as $field => $value) {
            if (str_ends_with($field, "_bounds")) {
                // This is a bounds field - map to low/high test fields
                $baseField = str_replace("_bounds", "", $field);

                if (is_array($value) && isset($value["low"], $value["high"])) {
                    // Map s_max_bounds to s_max_low and s_max_high
                    $this->test[$baseField . "_low"] = $value["low"];
                    $this->test[$baseField . "_high"] = $value["high"];
                }
            } else {
                // Regular field mapping
                if (array_key_exists($field, $this->test)) {
                    $this->test[$field] = $value;
                } elseif (array_key_exists($field, $this->batch)) {
                    $this->batch[$field] = $value;
                }
            }
        }

        // Handle batch info updates if batch data was extracted
        $hasExtractedBatchData = isset($extractedData["model"]) || isset($extractedData["color"]) || isset($extractedData["mcs"]) || isset($extractedData["code_alt"]);

        if ($hasExtractedBatchData && $this->canUpdateBatch()) {
            $this->update_batch = true;
        }
    }

    /**
     * Check if batch can be updated (for batch-test-create component)
     */
    private function canUpdateBatch(): bool
    {
        return ! $this->batch["model"] && ! $this->batch["color"] && ! $this->batch["mcs"] && ! $this->batch["code_alt"];
    }

    /**
     * Helper method to determine extraction type context
     */
    private function getExtractionContext(): string
    {
        // Determine if this is batch extraction or test extraction based on properties
        if (property_exists($this, "batch") && is_array($this->batch)) {
            return "test";
        } elseif (property_exists($this, "model") && is_string($this->model)) {
            return "batch";
        }
        return "unknown";
    }

    private function extractValueFromText(array $lines, string $pattern): ?string
    {
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match("/" . $pattern . "/i", $line, $matches)) {
                // Return the first capture group if it exists, otherwise the full match
                return isset($matches[1]) ? $matches[1] : $matches[0];
            }
        }
        return null;
    }

    private function processEvalValue(string $value): string
    {
        $value = strtolower(trim($value));
        return match ($value) {
            "ok", "pass" => "pass",
            "sl", "fail" => "fail",
            default => "",
        };
    }

    private function find3Digit($string): ?string
    {
        preg_match_all("/\/?(\d{3})/", (string) $string, $matches);
        return ! empty($matches[1]) ? end($matches[1]) : null;
    }

    private function safeString($value): string
    {
        return trim(preg_replace("/[^a-zA-Z0-9\s]/", "", (string) $value));
    }

    private function safeFloat($value): ?float
    {
        return is_numeric($value) ? (float) $value : 0;
    }

    public function getBoundFromString(string $range, string $type = "low"): ?float
    {
        if (empty($range) || ! str_contains($range, "-")) {
            return 0;
        }

        [$part1, $part2] = explode("-", $range, 2);

        $value1 = $this->safeFloat($part1);
        $value2 = $this->safeFloat($part2);

        $lower = min($value1, $value2);
        $higher = max($value1, $value2);

        return $type === "high" ? $higher : $lower;
    }

    public function removeFromQueue()
    {
        $batch = InsRubberBatch::find($this->batch["id"]);

        if ($batch) {
            $batch->update(["rdc_queue" => 0]);
            $this->js('toast("' . __("Dihapus dari antrian") . '", { type: "success" })');
            $this->js('$dispatch("close")');
            $this->dispatch("updated");
        } else {
            $this->handleNotFound();
        }
        $this->customReset();
    }

    public function save()
    {
        $test = new InsRdcTest();
        Gate::authorize("manage", $test);

        $this->validate();

        $batch = InsRubberBatch::find($this->batch["id"]);

        if (! $batch) {
            $this->customReset();
            $this->handleNotFound();
            return;
        }

        foreach ($this->batch as $key => $value) {
            if (in_array($key, ["id", "code"])) {
                continue;
            }

            if ($value) {
                $value = trim($value);
                $batch->$key = $value;
            }
        }

        $batch->rdc_queue = 0;
        $test->queued_at = $batch->updated_at;
        $batch->save();

        foreach ($this->test as $key => $value) {
            $test->$key = $value;
        }

        $test->user_id = Auth::user()->id;
        $test->ins_rubber_batch_id = $batch->id;
        $test->save();

        $this->js('$dispatch("close")');
        $this->js('toast("' . __("Hasil uji disimpan") . '", { type: "success" })');
        $this->dispatch("updated");
        $this->customReset();
    }

    public function getSelectedMachine()
    {
        return collect($this->machines)->firstWhere("id", $this->test["ins_rdc_machine_id"]);
    }
};

?>

<div>
    <div class="flex justify-between items-start p-6">
        <h2 class="text-lg font-medium text-neutral-900 dark:text-neutral-100">
            {{ $batch["code"] }}
        </h2>
        <x-text-button type="button" x-on:click="$dispatch('close')"><i class="icon-x"></i></x-text-button>
    </div>
    <div class="grid grid-cols-6">
        <div class="col-span-2 px-6 bg-caldy-500 bg-opacity-10 rounded-r-xl">
            <div class="mt-6">
                <x-pill class="uppercase">{{ __("Batch") }}</x-pill>
            </div>
            <div class="mt-6">
                <label for="test-code_alt" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Kode alt.") }}</label>
                <x-text-input id="test-code_alt" wire:model="batch.code_alt" type="text" />
            </div>
            <div class="mt-6">
                <label for="test-model" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Model") }}</label>
                <x-text-input id="test-model" list="test-models" wire:model="batch.model" type="text" />
                <datalist id="test-models">
                    @foreach ($shoe_models as $shoe_model)
                        <option value="{{ $shoe_model }}"></option>
                    @endforeach
                </datalist>
            </div>
            <div class="mt-6">
                <label for="test-color" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Warna") }}</label>
                <x-text-input id="test-color" wire:model="batch.color" type="text" />
            </div>
            <div class="mt-6">
                <label for="test-mcs" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("MCS") }}</label>
                <x-text-input id="test-mcs" wire:model="batch.mcs" type="text" />
            </div>
            <div class="mt-6">
                <label for="test-status_test" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Status Test") }}</label>
                <x-select class="w-full" id="test-status_test" wire:model="test.status_test">
                    <option value=""></option>
                    <option value="new">New</option>
                    <option value="retest">Retest</option>
                </x-select>
            </div>
        </div>
        <div class="col-span-4 px-6">
            <div
                class="flex gap-3"
                x-data="{
                    machine_id: @entangle("test.ins_rdc_machine_id"),
                    selectedMachine: null,
                    machines: @entangle("machines"),
                    updateSelectedMachine() {
                        this.selectedMachine = this.machines.find(
                            (m) => m.id == this.machine_id,
                        )
                    },
                }"
                x-init="updateSelectedMachine()"
                @change="updateSelectedMachine()"
            >
                <div class="grow">
                    <x-select class="w-full" id="test-machine_id" x-model="machine_id">
                        <option value="">{{ __("Pilih mesin") }}</option>
                        @foreach ($machines as $machine)
                            <option value="{{ $machine["id"] }}">
                                {{ $machine["number"] . " - " . $machine["name"] }}
                            </option>
                        @endforeach
                    </x-select>
                </div>
                <div x-cloak x-show="machine_id">
                    <input
                        wire:model="file"
                        type="file"
                        class="hidden"
                        x-cloak
                        x-ref="file"
                        x-bind:accept="
                            selectedMachine?.type === 'excel'
                                ? '.xls,.xlsx'
                                : selectedMachine?.type === 'txt'
                                  ? '.txt'
                                  : ''
                        "
                    />
                    <x-secondary-button type="button" class="w-full h-full justify-center" x-on:click="$refs.file.click()">
                        <i class="icon-upload mr-2"></i>
                        <span x-show="!selectedMachine?.type">?</span>
                        <span x-show="selectedMachine?.type === 'excel'">XLS</span>
                        <span x-show="selectedMachine?.type === 'txt'">TXT</span>
                    </x-secondary-button>
                </div>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-2 mt-6 gap-x-3">
                <div>
                    <div class="my-6">
                        <x-pill class="uppercase">{{ __("Shift") }}</x-pill>
                    </div>
                    <x-select class="w-full" id="test-shift" wire:model="test.shift">
                        <option value=""></option>
                        <option value="A">A</option>
                        <option value="B">B</option>
                        <option value="C">C</option>
                    </x-select>
                </div>
                <div>
                    <div class="my-6">
                        <x-pill class="uppercase">{{ __("Material Type") }}</x-pill>
                    </div>
                    <x-select class="w-full" id="test-material_type" wire:model="test.material_type">
                        <option value=""></option>
                        <option value="SOLID_RUBBER">SOLID RUBBER</option>
                        <option value="CLEAR_RUBBER">CLEAR RUBBER</option>
                        <option value="TCB">TCB</option>
                        <option value="EXWO_SOLID_RUBBER">EX WOOL SOLID RUBBER</option>
                        <option value="EXWO_CLEAR_RUBBER">EX WOOL CLEAR RUBBER</option>
                        <option value="EXPER_SOLID_RUBBER">EXPER SOLID RUBBER</option>
                        <option value="EXPER_CLEAR_RUBBER">EXPER CLEAR RUBBER</option>
                        <option value="EXPER_IP_12">EXPER IP 12</option>
                        <option value="EXPER_IP_15">EXPER IP 15</option>
                        <option value="SCRAP_RUBBER">SCRAP RUBBER</option>
                    </x-select>
                </div>
            </div>

            <div class="my-6">
                <x-pill class="uppercase">{{ __("Standar") }}</x-pill>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 mt-6">
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("S maks") }}</label>
                    <div class="flex w-full items-center">
                        <div class="grow">
                            <x-text-input-t class="text-center" wire:model="test.s_max_low" />
                        </div>
                        <div>-</div>
                        <div class="grow">
                            <x-text-input-t class="text-center" wire:model="test.s_max_high" />
                        </div>
                    </div>
                </div>
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("S min") }}</label>
                    <div class="flex w-full items-center">
                        <div class="grow">
                            <x-text-input-t class="text-center" wire:model="test.s_min_low" />
                        </div>
                        <div>-</div>
                        <div class="grow">
                            <x-text-input-t class="text-center" wire:model="test.s_min_high" />
                        </div>
                    </div>
                </div>
                <div>
                    <label for="test-type" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Tipe") }}</label>
                    <x-select class="w-full uppercase" id="test-type" wire:model="test.type">
                        <option value=""></option>
                        <option value="-">-</option>
                        <option value="slow">SLOW</option>
                        <option value="fast">FAST</option>
                    </x-select>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 mt-6">
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("TC10") }}</label>
                    <div class="flex w-full items-center">
                        <div class="grow">
                            <x-text-input-t class="text-center" wire:model="test.tc10_low" />
                        </div>
                        <div>-</div>
                        <div class="grow">
                            <x-text-input-t class="text-center" wire:model="test.tc10_high" />
                        </div>
                    </div>
                </div>
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("TC50") }}</label>
                    <div class="flex w-full items-center">
                        <div class="grow">
                            <x-text-input-t class="text-center" wire:model="test.tc50_low" />
                        </div>
                        <div>-</div>
                        <div class="grow">
                            <x-text-input-t class="text-center" wire:model="test.tc50_high" />
                        </div>
                    </div>
                </div>
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("TC90") }}</label>
                    <div class="flex w-full items-center">
                        <div class="grow">
                            <x-text-input-t class="text-center" wire:model="test.tc90_low" />
                        </div>
                        <div>-</div>
                        <div class="grow">
                            <x-text-input-t class="text-center" wire:model="test.tc90_high" />
                        </div>
                    </div>
                </div>
            </div>
            <div class="my-6">
                <x-pill class="uppercase">{{ __("Hasil") }}</x-pill>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-x-3 mt-6">
                <div>
                    <label for="test-s_max" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("S maks") }}</label>
                    <x-text-input id="test-s_max" wire:model="test.s_max" type="number" step=".01" />
                </div>
                <div>
                    <label for="test-s_min" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("S Min") }}</label>
                    <x-text-input id="test-s_min" wire:model="test.s_min" type="number" step=".01" />
                </div>
                <div>
                    <label for="test-eval" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Evaluasi") }}</label>
                    <x-select class="w-full" id="test-eval" wire:model="test.eval">
                        <option value=""></option>
                        <option value="pass">{{ __("PASS") }}</option>
                        <option value="fail">{{ __("FAIL") }}</option>
                    </x-select>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-x-3 mt-6">
                <div>
                    <label for="test-tc10" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("TC10") }}</label>
                    <x-text-input id="test-tc10" wire:model="test.tc10" type="number" step=".01" />
                </div>
                <div>
                    <label for="test-tc50" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("TC50") }}</label>
                    <x-text-input id="test-tc50" wire:model="test.tc50" type="number" step=".01" />
                </div>
                <div>
                    <label for="test-tc90" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("TC90") }}</label>
                    <x-text-input id="test-tc90" wire:model="test.tc90" type="number" step=".01" />
                </div>
            </div>
        </div>
    </div>
    @if ($errors->any())
        <div class="px-6 mt-6">
            <x-input-error :messages="$errors->first()" />
        </div>
    @endif

    <div class="p-6 flex justify-between items-center gap-3">
        <x-dropdown align="left" width="48">
            <x-slot name="trigger">
                <x-text-button><i class="icon-ellipsis-vertical"></i></x-text-button>
            </x-slot>
            <x-slot name="content">
                <x-dropdown-link href="#" wire:click.prevent="customResetBatch">
                    {{ __("Reset") }}
                </x-dropdown-link>
                <hr class="border-neutral-300 dark:border-neutral-600" />
                <x-dropdown-link href="#" wire:click.prevent="removeFromQueue">
                    {{ __("Hapus dari antrian") }}
                </x-dropdown-link>
            </x-slot>
        </x-dropdown>
        <x-primary-button type="button" wire:click="save">
            {{ __("Simpan") }}
        </x-primary-button>
    </div>
    <x-spinner-bg wire:loading.class.remove="hidden"></x-spinner-bg>
    <x-spinner wire:loading.class.remove="hidden" class="hidden"></x-spinner>
</div>
