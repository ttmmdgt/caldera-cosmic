<?php

use Livewire\Volt\Component;
use App\Models\InsStcModels;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;

new class extends Component {
    public int $id;
    public string $name = "";
    public array $std_temperature = [[0, 0], [0, 0], [0, 0], [0, 0]];
    public array $std_duration = [2700, 2700];
    public string $status = 'active';

    public function rules()
    {
        return [
            "name" => ["required", "string", "min:1", "max:100", Rule::unique("ins_stc_models", "name")->ignore($this->id ?? null)],
            "std_temperature" => ["array", "size:4"],
            "std_temperature.*" => ["array", "size:2"],
            "std_temperature.*.*" => ["numeric", "min:0", "max:200"],
            "std_duration" => ["array", "size:2"],
            "std_duration.*" => ["numeric", "min:1"],
            "status" => ["required", "in:active,inactive"],
        ];
    }

    public function updatedStatus($value)
    {
        $this->status = $value ? 'active' : 'inactive';
    }

    #[On("model-edit")]
    public function loadModel(int $id)
    {
        $model = InsStcModels::find($id);
        if ($model) {
            $this->id = $model->id;
            $this->name = $model->name;
            $this->std_temperature = is_array($model->std_temperature) ? $model->std_temperature : [[0, 0], [0, 0], [0, 0], [0, 0]];
            $this->std_duration = is_array($model->std_duration) ? $model->std_duration : [2700, 2700];
            $this->status = $model->status;

            $this->resetValidation();
        } else {
            $this->handleNotFound();
        }
    }

    public function save()
    {
        $model = InsStcModels::find($this->id);
        $this->name = strtoupper(trim($this->name));
        $validated = $this->validate();

        if ($model) {
            Gate::authorize("manage", $model);

            $model->update([
                "name" => $validated["name"],
                "std_temperature" => $validated["std_temperature"],
                "std_duration" => $validated["std_duration"],
                "status" => $this->status,
            ]);

            $this->js('$dispatch("close")');
            $this->js('toast("' . __("Model diperbarui") . '", { type: "success" })');
            $this->dispatch("updated");
        } else {
            $this->handleNotFound();
            $this->customReset();
        }
    }

    public function delete()
    {
        $model = InsStcModels::find($this->id);

        if ($model) {
            Gate::authorize("manage", $model);
            $model->delete();

            $this->js('$dispatch("close")');
            $this->js('toast("' . __("Model dihapus") . '", { type: "success" })');
            $this->dispatch("updated");
        } else {
            $this->handleNotFound();
        }
    }

    public function customReset()
    {
        $this->reset(["name", "std_temperature", "std_duration", "status"]);
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
                {{ __("Model") }}
            </h2>
            <x-text-button type="button" x-on:click="$dispatch('close')"><i class="icon-x"></i></x-text-button>
        </div>
        <div class="mt-6">
            <div>
                <label for="model-name" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Nama Model") }}</label>
                <x-text-input id="model-name" wire:model="name" type="text" :disabled="Gate::denies('manage', InsStcModels::class)" />
                @error("name")
                    <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                @enderror
            </div>
        </div>

        <div class="mt-6">
            <h2 class="font-medium text-neutral-900 dark:text-neutral-100 mb-3">
                {{ __("Standard Temperature") }}
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs uppercase text-neutral-500 mb-2">{{ __("Zone 1 (°C)") }}</label>
                    <div class="grid grid-cols-2 gap-2">
                        <x-text-input-t class="text-center" placeholder="Min" wire:model="std_temperature.0.0" type="number" min="0" max="200" :disabled="Gate::denies('manage', InsStcModels::class)" />
                        <x-text-input-t class="text-center" placeholder="Max" wire:model="std_temperature.0.1" type="number" min="0" max="200" :disabled="Gate::denies('manage', InsStcModels::class)" />
                    </div>
                </div>
                <div>
                    <label class="block text-xs uppercase text-neutral-500 mb-2">{{ __("Zone 2 (°C)") }}</label>
                    <div class="grid grid-cols-2 gap-2">
                        <x-text-input-t class="text-center" placeholder="Min" wire:model="std_temperature.1.0" type="number" min="0" max="200" :disabled="Gate::denies('manage', InsStcModels::class)" />
                        <x-text-input-t class="text-center" placeholder="Max" wire:model="std_temperature.1.1" type="number" min="0" max="200" :disabled="Gate::denies('manage', InsStcModels::class)" />
                    </div>
                </div>
                <div>
                    <label class="block text-xs uppercase text-neutral-500 mb-2">{{ __("Zone 3 (°C)") }}</label>
                    <div class="grid grid-cols-2 gap-2">
                        <x-text-input-t class="text-center" placeholder="Min" wire:model="std_temperature.2.0" type="number" min="0" max="200" :disabled="Gate::denies('manage', InsStcModels::class)" />
                        <x-text-input-t class="text-center" placeholder="Max" wire:model="std_temperature.2.1" type="number" min="0" max="200" :disabled="Gate::denies('manage', InsStcModels::class)" />
                    </div>
                </div>
                <div>
                    <label class="block text-xs uppercase text-neutral-500 mb-2">{{ __("Zone 4 (°C)") }}</label>
                    <div class="grid grid-cols-2 gap-2">
                        <x-text-input-t class="text-center" placeholder="Min" wire:model="std_temperature.3.0" type="number" min="0" max="200" :disabled="Gate::denies('manage', InsStcModels::class)" />
                        <x-text-input-t class="text-center" placeholder="Max" wire:model="std_temperature.3.1" type="number" min="0" max="200" :disabled="Gate::denies('manage', InsStcModels::class)" />
                    </div>
                </div>
            </div>
            @error("std_temperature.*.*")
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
                    <x-text-input-t class="text-center" placeholder="2700" wire:model="std_duration.0" type="number" min="1" :disabled="Gate::denies('manage', InsStcModels::class)" />
                    <x-text-input-t class="text-center" placeholder="2700" wire:model="std_duration.1" type="number" min="1" :disabled="Gate::denies('manage', InsStcModels::class)" />
                </div>
            </div>
            @error("std_duration.*")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
        </div>

        <div class="mt-6">
            <div class="flex justify-between items-center">
                <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Status") }}</label>
                <div>
                    <x-toggle wire:model.live="status" x-bind:checked="$wire.status === 'active'" x-on:click="$wire.status = ($wire.status === 'active' ? 'inactive' : 'active')" :disabled="Gate::denies('manage', InsStcModels::class)">{{ __("Aktif") }}</x-toggle>
                    @error("status")
                        <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
                    @enderror
                </div>
            </div>
        </div>
        <div class="mt-6 flex justify-between">
            @can("manage", InsStcModels::class)
                <x-danger-button type="button" x-on:click="$dispatch('open-modal', 'model-delete-{{ $id }}')">
                    {{ __("Hapus") }}
                </x-danger-button>
            @endcan
            @can("manage", InsStcModels::class)
                <x-primary-button type="submit">
                    {{ __("Simpan") }}
                </x-primary-button>
            @endcan
        </div>
    </form>
    <x-spinner-bg wire:loading.class.remove="hidden"></x-spinner-bg>
    <x-spinner wire:loading.class.remove="hidden" class="hidden"></x-spinner>

    <x-modal name="model-delete-{{ $id }}" maxWidth="md">
        <div class="p-6">
            <h2 class="text-lg font-medium text-neutral-900 dark:text-neutral-100">
                {{ __("Hapus model?") }}
            </h2>
            <p class="mt-3 text-sm text-neutral-600 dark:text-neutral-400">
                {{ __("Tindakan ini permanen.") }}
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    {{ __("Batal") }}
                </x-secondary-button>
                <x-danger-button type="button" wire:click="delete">
                    {{ __("Hapus") }}
                </x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
