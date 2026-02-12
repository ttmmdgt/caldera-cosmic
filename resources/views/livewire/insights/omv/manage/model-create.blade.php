<?php

use Livewire\Volt\Component;
use App\Models\InsRubberModel;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;

new class extends Component {
    public string $name = "";
    public string $description = "";

    public function rules()
    {
        return [
            "name" => ["required", "min:1", "max:140", "unique:ins_rubber_models"],
            "description" => ["nullable", "string", "max:255"],
        ];
    }

    public function save()
    {
        $model = new InsRubberModel();
        Gate::authorize("manage", $model);

        $this->name = strtoupper(trim($this->name));
        $validated = $this->validate();

        $model->fill([
            "name" => $validated["name"],
            "description" => $validated["description"] ?? null,
        ]);

        $model->save();

        $this->js('$dispatch("close")');
        $this->js('toast("' . __("Model dibuat") . '", { type: "success" })');
        $this->dispatch("updated");

        $this->customReset();
    }

    public function customReset()
    {
        $this->reset(["name", "description"]);
    }
};
?>

<div>
    <form wire:submit="save" class="p-6">
        <div class="flex justify-between items-start">
            <h2 class="text-lg font-medium text-neutral-900 dark:text-neutral-100">
                {{ __("Model baru") }}
            </h2>
            <x-text-button type="button" x-on:click="$dispatch('close')"><i class="icon-x"></i></x-text-button>
        </div>
        <div class="mt-6">
            <label for="model-name" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Nama") }}</label>
            <x-text-input id="model-name" wire:model="name" type="text" />
            @error("name")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
        </div>
        <div class="mt-6">
            <label for="model-description" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Deskripsi") }}</label>
            <x-text-input id="model-description" wire:model="description" type="text" />
            @error("description")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
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
