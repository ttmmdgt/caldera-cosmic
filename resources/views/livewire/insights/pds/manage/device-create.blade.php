<?php

use Livewire\Volt\Component;
use App\Models\InsPhDosingDevice;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;

new class extends Component {
    public string $name = "";
    public string $plant = "";
    public string $ip_address = "";
    public bool $is_active = true;
    public string $tpm_code = "";
    
    // Standard pH
    public string $standard_ph_min = "";
    public string $standard_ph_max = "";
    
    // Formula pH (each formula has 2 values)
    public string $formula_ph_1_1 = "";
    public string $formula_ph_2_1 = "";
    public string $formula_ph_2_2 = "";
    public string $formula_ph_3_1 = "";
    public string $formula_ph_3_2 = "";
    
    // Formula Timer
    public string $formula_timer_1 = "";
    public string $formula_timer_2 = "";
    public string $formula_timer_3 = "";

    public function rules()
    {
        $rules = [
            "name" => ["required", "string", "min:1", "max:50"],
            "plant" => ["required", "string", "min:1", "max:50"],
            "ip_address" => ["required", "ip", Rule::unique('ins_ph_dosing_devices', 'ip_address')],
            "is_active" => ["boolean"],
            "tpm_code" => ["nullable", "string", "max:50"],
            "standard_ph_min" => ["nullable", "numeric"],
            "standard_ph_max" => ["nullable", "numeric"],
            "formula_ph_1_1" => ["nullable", "numeric"],
            "formula_ph_2_1" => ["nullable", "numeric"],
            "formula_ph_2_2" => ["nullable", "numeric"],
            "formula_ph_3_1" => ["nullable", "numeric"],
            "formula_ph_3_2" => ["nullable", "numeric"],
            "formula_timer_1" => ["nullable", "integer"],
            "formula_timer_2" => ["nullable", "integer"],
            "formula_timer_3" => ["nullable", "integer"],
        ];

        return $rules;
    }

    public function save()
    {
        $device = new InsPhDosingDevice();
        Gate::authorize("manage", $device);

        $this->name = trim($this->name);
        $this->plant = trim($this->plant);
        $this->tpm_code = trim($this->tpm_code);
        $validated = $this->validate();

        // Build config JSON structure
        $config = [
            "tpm_code" => $this->tpm_code,
            "standard_ph" => [
                "min" => !empty($validated["standard_ph_min"]) ? (float)$validated["standard_ph_min"] : null,
                "max" => !empty($validated["standard_ph_max"]) ? (float)$validated["standard_ph_max"] : null,
            ],
            "formula_ph" => [
                "formula_1" => [
                    !empty($validated["formula_ph_1_1"]) ? (float)$validated["formula_ph_1_1"] : null,
                ],
                "formula_2" => [
                    !empty($validated["formula_ph_2_1"]) ? (float)$validated["formula_ph_2_1"] : null,
                    !empty($validated["formula_ph_2_2"]) ? (float)$validated["formula_ph_2_2"] : null,
                ],
                "formula_3" => [
                    !empty($validated["formula_ph_3_1"]) ? (float)$validated["formula_ph_3_1"] : null,
                    !empty($validated["formula_ph_3_2"]) ? (float)$validated["formula_ph_3_2"] : null,
                ],
            ],
            "formula_timer" => [
                "formula_1" => !empty($validated["formula_timer_1"]) ? (int)$validated["formula_timer_1"] : null,
                "formula_2" => !empty($validated["formula_timer_2"]) ? (int)$validated["formula_timer_2"] : null,
                "formula_3" => !empty($validated["formula_timer_3"]) ? (int)$validated["formula_timer_3"] : null,
            ],
        ];
        
        $device->fill([
            "name" => $validated["name"],
            "plant" => $validated["plant"],
            "ip_address" => $validated["ip_address"],
            "config" => $config,
            "is_active" => $validated["is_active"],
        ]);

        $device->save();

        $this->js('$dispatch("close")');
        $this->js('toast("' . __("Perangkat dibuat") . '", { type: "success" })');
        $this->dispatch("updated");

        $this->customReset();
    }

    public function customReset()
    {
        $this->reset([
            "name", "plant", "ip_address", "is_active", "tpm_code",
            "standard_ph_min", "standard_ph_max",
            "formula_ph_1_1",
            "formula_ph_2_1", "formula_ph_2_2",
            "formula_ph_3_1", "formula_ph_3_2",
            "formula_timer_1", "formula_timer_2", "formula_timer_3"
        ]);
        $this->is_active = true;
    }
};
?>

<div>
    <form wire:submit="save" class="p-6">
        <div class="flex justify-between items-start">
            <h2 class="text-lg font-medium text-neutral-900 dark:text-neutral-100">
                {{ __("Perangkat baru") }}
            </h2>
            <x-text-button type="button" x-on:click="$dispatch('close')"><i class="icon-x"></i></x-text-button>
        </div>
        <div class="mt-6">
            <label for="device-name" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Nama") }}</label>
            <x-text-input id="device-name" wire:model="name" type="text" />
            @error("name")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
        </div>
        <div class="mt-6">
            <label for="device-plant" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Plant") }}</label>
            <x-text-input id="device-plant" wire:model="plant" type="text" placeholder="E" />
            @error("plant")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
        </div>
        <div class="mt-6">
            <label for="device-ip" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("IP Address") }}</label>
            <x-text-input id="device-ip" wire:model="ip_address" type="text" placeholder="172.70.88.199" />
            @error("ip_address")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
        </div>
        <div class="mt-6">
            <label for="device-tpm-code" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("TPM Code") }}</label>
            <x-text-input id="device-tpm-code" wire:model="tpm_code" type="text" placeholder="DGR-002" />
            @error("tpm_code")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
        </div>

        <!-- Standard pH Section -->
        <div class="mt-6">
            <h3 class="text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-3">{{ __("Standard pH") }}</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="standard-ph-min" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Min") }}</label>
                    <x-text-input id="standard-ph-min" wire:model="standard_ph_min" type="number" step="0.01" placeholder="1.30" />
                    @error("standard_ph_min")
                        <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                    @enderror
                </div>
                <div>
                    <label for="standard-ph-max" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Max") }}</label>
                    <x-text-input id="standard-ph-max" wire:model="standard_ph_max" type="number" step="0.01" placeholder="3.00" />
                    @error("standard_ph_max")
                        <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                    @enderror
                </div>
            </div>
        </div>

        <!-- Formula pH Section -->
        <div class="mt-6">
            <h3 class="text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-3">{{ __("Formula pH") }}</h3>
            <div class="space-y-3">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="formula-ph-1-1" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Formula 1") . " (" . __("PH To High") . ")" }}</label>
                        <x-text-input id="formula-ph-1-1" wire:model="formula_ph_1_1" type="number" step="0.01" placeholder="1.00" />
                        @error("formula_ph_1_1")
                            <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                        @enderror
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="formula-ph-2-1" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Formula 2") . " (" . __("PH High") . ")" . " (" . __("Min") . ")" }}</label>
                        <x-text-input id="formula-ph-2-1" wire:model="formula_ph_2_1" type="number" step="0.01" placeholder="2.00" />
                        @error("formula_ph_2_1")
                            <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                        @enderror
                    </div>
                    <div>
                        <label for="formula-ph-2-2" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Formula 2") . " (" . __("PH High") . ")" . " (" . __("Max") . ")" }}</label>
                        <x-text-input id="formula-ph-2-2" wire:model="formula_ph_2_2" type="number" step="0.01" placeholder="3.00" />
                        @error("formula_ph_2_2")
                            <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                        @enderror
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="formula-ph-3-1" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Formula 3") . " (" . __("PH To Middle") . ")" . " (" . __("Min") . ")" }}</label>
                        <x-text-input id="formula-ph-3-1" wire:model="formula_ph_3_1" type="number" step="0.01" placeholder="3.00" />
                        @error("formula_ph_3_1")
                            <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                        @enderror
                    </div>
                    <div>
                        <label for="formula-ph-3-2" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Formula 3") . " (" . __("PH To Middle") . ")" . " (" . __("Max") . ")" }}</label>
                        <x-text-input id="formula-ph-3-2" wire:model="formula_ph_3_2" type="number" step="0.01" placeholder="4.00" />
                        @error("formula_ph_3_2")
                            <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        <!-- Formula Timer Section -->
        <div class="mt-6">
            <h3 class="text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-3">{{ __("Formula Timer (seconds)") }}</h3>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label for="formula-timer-1" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Formula 1") }}</label>
                    <x-text-input id="formula-timer-1" wire:model="formula_timer_1" type="number" placeholder="30" />
                    @error("formula_timer_1")
                        <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                    @enderror
                </div>
                <div>
                    <label for="formula-timer-2" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Formula 2") }}</label>
                    <x-text-input id="formula-timer-2" wire:model="formula_timer_2" type="number" placeholder="60" />
                    @error("formula_timer_2")
                        <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                    @enderror
                </div>
                <div>
                    <label for="formula-timer-3" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Formula 3") }}</label>
                    <x-text-input id="formula-timer-3" wire:model="formula_timer_3" type="number" placeholder="30" />
                    @error("formula_timer_3")
                        <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                    @enderror
                </div>
            </div>
        </div>

        <div class="mt-6">
            <label class="flex items-center">
                <input type="checkbox" wire:model="is_active" class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:focus:ring-blue-600 dark:focus:ring-offset-gray-800">
                <span class="ml-2 text-sm text-gray-600 dark:text-gray-400">{{ __("Aktif") }}</span>
            </label>
        </div>
        <div class="mt-6 flex justify-end">
            <x-primary-button type="submit">
                {{ __("Simpan") }}
            </x-primary-button>
        </div>
    </form>
    <x-spinner-bg wire:loading.class.remove="hidden"></x-spinner-bg>
    <x-spinner wire:loading.class.remove="hidden" class="hidden"></x-spinner>
</div>
