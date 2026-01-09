<?php

use Livewire\Volt\Component;

use App\Models\InsStcMachine;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;

new class extends Component {
    public int $id;

    public string $code;
    public string $name = "";
    public int $line;
    public string $ip_address;
    public bool $is_at_adjusted = false;
    public array $at_adjust_strength = [];
    public array $section_limits_high = [];
    public array $section_limits_low = [];
    public array $std_duration = [];

    public function rules()
    {
        return [
            "code" => ["required", "string", "min:1", "max:20", Rule::unique("ins_stc_machines", "code")->ignore($this->id ?? null)],
            "name" => ["required", "string", "min:1", "max:20"],
            "line" => ["required", "integer", "min:1", "max:99"],
            "ip_address" => ["required", "ipv4", Rule::unique("ins_stc_machines", "ip_address")->ignore($this->id ?? null)],
            "is_at_adjusted" => ["boolean"],
            "at_adjust_strength" => ["array"],
            "at_adjust_strength.upper" => ["array", "size:8"],
            "at_adjust_strength.upper.*" => ["numeric", "min:0", "max:100"],
            "at_adjust_strength.lower" => ["array", "size:8"],
            "at_adjust_strength.lower.*" => ["numeric", "min:0", "max:100"],
            "section_limits_high" => ["array", "size:8"],
            "section_limits_high.*" => ["numeric", "min:30", "max:99"],
            "section_limits_low" => ["array", "size:8"],
            "section_limits_low.*" => ["numeric", "min:30", "max:99"],
            "std_duration" => ["array", "size:2"],
            "std_duration.*" => ["required", "integer", "min:1", "max:9999"],
        ];
    }

    #[On("machine-edit")]
    public function loadMachine(int $id)
    {
        $machine = InsStcMachine::find($id);
        if ($machine) {
            $this->id = $machine->id;
            $this->code = $machine->code;
            $this->name = $machine->name;
            $this->line = $machine->line;
            $this->ip_address = $machine->ip_address;
            $this->is_at_adjusted = $machine->is_at_adjusted;
            $this->at_adjust_strength = is_array($machine->at_adjust_strength) ? $machine->at_adjust_strength : ["upper" => [0, 0, 0, 0, 0, 0, 0, 0], "lower" => [0, 0, 0, 0, 0, 0, 0, 0]];
            $this->section_limits_high = is_array($machine->section_limits_high) ? $machine->section_limits_high : [83, 78, 73, 68, 63, 58, 53, 48];
            $this->section_limits_low = is_array($machine->section_limits_low) ? $machine->section_limits_low : [73, 68, 63, 58, 53, 48, 43, 38];
            $this->std_duration = $machine->std_duration ? (is_array($machine->std_duration) ? $machine->std_duration : json_decode($machine->std_duration, true)) : [60, 60];

            $this->resetValidation();
        } else {
            $this->handleNotFound();
        }
    }

    public function save()
    {
        $machine = InsStcMachine::find($this->id);
        $this->code = strtoupper(trim($this->code));
        $this->name = strtoupper(trim($this->name));
        $validated = $this->validate();

        // Additional validation: ensure high limits are greater than low limits
        for ($i = 0; $i < 8; $i++) {
            if ($this->section_limits_high[$i] <= $this->section_limits_low[$i]) {
                $this->addError("section_limits_high.{$i}", __("Batas tinggi harus lebih besar dari batas rendah untuk bagian :section", ["section" => $i + 1]));
                return;
            }
        }

        if ($machine) {
            Gate::authorize("manage", $machine);

            $machine->update([
                "code" => $validated["code"],
                "name" => $validated["name"],
                "line" => $validated["line"],
                "ip_address" => $validated["ip_address"],
                "is_at_adjusted" => $validated["is_at_adjusted"],
                "at_adjust_strength" => $validated["at_adjust_strength"],
                "section_limits_high" => $validated["section_limits_high"],
                "section_limits_low" => $validated["section_limits_low"],
                "std_duration" => $validated["std_duration"],
            ]);

            $this->js('$dispatch("close")');
            $this->js('toast("' . __("Mesin diperbarui") . '", { type: "success" })');
            $this->dispatch("updated");
        } else {
            $this->handleNotFound();
            $this->customReset();
        }
    }

    public function customReset()
    {
        $this->reset(["code", "name", "line", "ip_address", "is_at_adjusted", "at_adjust_strength", "section_limits_high", "section_limits_low"]);
    }

    public function handleNotFound()
    {
        $this->js('$dispatch("close")');
        $this->js('toast("' . __("Tidak ditemukan") . '", { type: "danger" })');
        $this->dispatch("updated");
    }
};
?>

<div>
    <form wire:submit="save" class="p-6">
        <div class="flex justify-between items-start">
            <h2 class="text-lg font-medium text-neutral-900 dark:text-neutral-100">
                {{ __("Mesin ") }}
            </h2>
            <x-text-button type="button" x-on:click="$dispatch('close')"><i class="icon-x"></i></x-text-button>
        </div>
        <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label for="machine-code" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Kode") }}</label>
                <x-text-input id="machine-code" wire:model="code" type="text" :disabled="Gate::denies('manage', InsStcMachine::class)" />
                @error("code")
                    <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                @enderror
            </div>
            <div>
                <label for="machine-name" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Nama") }}</label>
                <x-text-input id="machine-name" wire:model="name" type="text" :disabled="Gate::denies('manage', InsStcMachine::class)" />
                @error("name")
                    <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                @enderror
            </div>
            <div>
                <label for="machine-line" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Line") }}</label>
                <x-text-input id="machine-line" wire:model="line" type="number" :disabled="Gate::denies('manage', InsStcMachine::class)" />
                @error("line")
                    <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                @enderror
            </div>
            <div>
                <label for="machine-ip-address" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Alamat IP") }}</label>
                <x-text-input id="machine-ip-address" wire:model="ip_address" :disabled="Gate::denies('manage', InsStcMachine::class)" type="text" />
                @error("ip_address")
                    <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                @enderror
            </div>
        </div>
        <div class="mt-6">
            <h2 class="font-medium text-neutral-900 dark:text-neutral-100 mb-3">
                {{ __("Batas SV") }}
            </h2>
            <div class="mb-3">
                <label class="block text-xs uppercase text-neutral-500">{{ __("Maksimum") }}</label>
                <div class="grid grid-cols-8 gap-1">
                    <x-text-input-t
                        class="text-center"
                        placeholder="83"
                        wire:model="section_limits_high.0"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="30"
                        max="99"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="78"
                        wire:model="section_limits_high.1"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="30"
                        max="99"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="73"
                        wire:model="section_limits_high.2"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="30"
                        max="99"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="68"
                        wire:model="section_limits_high.3"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="30"
                        max="99"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="63"
                        wire:model="section_limits_high.4"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="30"
                        max="99"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="58"
                        wire:model="section_limits_high.5"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="30"
                        max="99"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="53"
                        wire:model="section_limits_high.6"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="30"
                        max="99"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="48"
                        wire:model="section_limits_high.7"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="30"
                        max="99"
                    />
                </div>
            </div>
            <div>
                <label class="block text-xs uppercase text-neutral-500">{{ __("Minimum") }}</label>
                <div class="grid grid-cols-8 gap-1">
                    <x-text-input-t
                        class="text-center"
                        placeholder="73"
                        wire:model="section_limits_low.0"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="30"
                        max="99"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="68"
                        wire:model="section_limits_low.1"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="30"
                        max="99"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="63"
                        wire:model="section_limits_low.2"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="30"
                        max="99"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="58"
                        wire:model="section_limits_low.3"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="30"
                        max="99"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="53"
                        wire:model="section_limits_low.4"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="30"
                        max="99"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="48"
                        wire:model="section_limits_low.5"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="30"
                        max="99"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="43"
                        wire:model="section_limits_low.6"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="30"
                        max="99"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="38"
                        wire:model="section_limits_low.7"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="30"
                        max="99"
                    />
                </div>
            </div>
            @error("section_limits_high.*")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror

            @error("section_limits_low.*")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
        </div>

        <div class="mt-6">
            <div class="flex justify-between items-center mb-3">
                <h2 class="font-medium text-neutral-900 dark:text-neutral-100">
                    {{ __("Penyetelan AT") }}
                </h2>
                <div>
                    <x-toggle wire:model="is_at_adjusted" :disabled="Gate::denies('manage', InsStcMachine::class)">{{ __("Aktifkan") }}</x-toggle>
                    @error("is_at_adjusted")
                        <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                    @enderror
                </div>
            </div>
            <div class="my-3" x-show="$wire.is_at_adjusted">
                <label class="block mb-1 text-xs text-neutral-500 uppercase">{{ __("Upper") . " (%)" }}</label>
                <div class="grid grid-cols-8 gap-1">
                    <x-text-input-t
                        class="text-center"
                        placeholder="0"
                        wire:model="at_adjust_strength.upper.0"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="0"
                        max="100"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="0"
                        wire:model="at_adjust_strength.upper.1"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="0"
                        max="100"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="0"
                        wire:model="at_adjust_strength.upper.2"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="0"
                        max="100"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="0"
                        wire:model="at_adjust_strength.upper.3"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="0"
                        max="100"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="0"
                        wire:model="at_adjust_strength.upper.4"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="0"
                        max="100"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="0"
                        wire:model="at_adjust_strength.upper.5"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="0"
                        max="100"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="0"
                        wire:model="at_adjust_strength.upper.6"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="0"
                        max="100"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="0"
                        wire:model="at_adjust_strength.upper.7"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="0"
                        max="100"
                    />
                </div>
            </div>
            <div x-show="$wire.is_at_adjusted">
                <label class="block mb-1 text-xs text-neutral-500 uppercase">{{ __("Lower") . " (%)" }}</label>
                <div class="grid grid-cols-8 gap-1">
                    <x-text-input-t
                        class="text-center"
                        placeholder="0"
                        wire:model="at_adjust_strength.lower.0"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="0"
                        max="100"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="0"
                        wire:model="at_adjust_strength.lower.1"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="0"
                        max="100"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="0"
                        wire:model="at_adjust_strength.lower.2"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="0"
                        max="100"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="0"
                        wire:model="at_adjust_strength.lower.3"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="0"
                        max="100"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="0"
                        wire:model="at_adjust_strength.lower.4"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="0"
                        max="100"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="0"
                        wire:model="at_adjust_strength.lower.5"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="0"
                        max="100"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="0"
                        wire:model="at_adjust_strength.lower.6"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="0"
                        max="100"
                    />
                    <x-text-input-t
                        class="text-center"
                        placeholder="0"
                        wire:model="at_adjust_strength.lower.7"
                        :disabled="Gate::denies('manage', InsStcMachine::class)"
                        type="number"
                        min="0"
                        max="100"
                    />
                </div>
            </div>
            @error("at_adjust_strength.upper.*")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror

            @error("at_adjust_strength.lower.*")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
        </div>

        <div class="mt-6">
            <h2 class="font-medium text-neutral-900 dark:text-neutral-100 mb-3">
                {{ __("Durasi Standar") }}
            </h2>
            <div class="mb-3 mt-2">
                <label class="block text-xs uppercase text-neutral-500">{{ __("Durasi Standar Operasi (detik)") }}</label>
                <div class="grid grid-cols-4 gap-2 mt-2">
                    <x-text-input-t class="text-center" placeholder="60" wire:model="std_duration.0" type="number" min="1" :disabled="Gate::denies('manage', InsStcMachine::class)" />
                    <x-text-input-t class="text-center" placeholder="60" wire:model="std_duration.1" type="number" min="1" :disabled="Gate::denies('manage', InsStcMachine::class)" />
                </div>
            </div>
            @error("std_duration.*")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
        </div>

        @can("manage", InsStcMachine::class)
            <div class="mt-6 flex justify-end">
                <x-primary-button type="submit">
                    {{ __("Simpan") }}
                </x-primary-button>
            </div>
        @endcan
    </form>
    <x-spinner-bg wire:loading.class.remove="hidden"></x-spinner-bg>
    <x-spinner wire:loading.class.remove="hidden" class="hidden"></x-spinner>
</div>
