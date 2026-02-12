<?php

use Livewire\Volt\Component;
use App\Models\InsRubberModel;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;

new class extends Component {
    public int $id;
    public string $name = "";
    public string $description = "";

    public function rules()
    {
        return [
            "name" => ["required", "min:1", "max:140", Rule::unique("ins_rubber_models", "name")->ignore($this->id ?? null)],
            "description" => ["nullable", "string", "max:255"],
        ];
    }

    #[On("model-edit")]
    public function loadModel(int $id)
    {
        $model = InsRubberModel::find($id);
        if ($model) {
            $this->id = $model->id;
            $this->name = $model->name;
            $this->description = $model->description ?? "";
            $this->resetValidation();
        } else {
            $this->handleNotFound();
        }
    }

    public function save()
    {
        $model = InsRubberModel::find($this->id);

        $this->name = strtoupper(trim($this->name));
        $validated = $this->validate();

        if ($model) {
            Gate::authorize("manage", $model);

            $model->update([
                "name" => $validated["name"],
                "description" => $validated["description"] ?? null,
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
        $model = InsRubberModel::find($this->id);

        if ($model) {
            Gate::authorize("manage", $model);

            try {
                $model->delete();
                $this->js('$dispatch("close")');
                $this->js('toast("' . __("Model dihapus") . '", { type: "success" })');
                $this->dispatch("updated");
                $this->customReset();
            } catch (\Exception $e) {
                $this->js('toast("' . __("Gagal menghapus. Model masih digunakan.") . '", { type: "danger" })');
            }
        } else {
            $this->handleNotFound();
        }
    }

    public function customReset()
    {
        $this->reset(["name", "description"]);
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
            <label for="model-name" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Nama") }}</label>
            <x-text-input id="model-name" wire:model="name" type="text" :disabled="Gate::denies('manage', InsRubberModel::class)" />
            @error("name")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
        </div>
        <div class="mt-6">
            <label for="model-description" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Deskripsi") }}</label>
            <x-text-input id="model-description" wire:model="description" type="text" :disabled="Gate::denies('manage', InsRubberModel::class)" />
            @error("description")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
        </div>

        @can("manage", InsRubberModel::class)
            <div class="mt-6 flex justify-between items-end">
                <div>
                    <x-text-button type="button" class="uppercase text-xs text-red-500" wire:click="delete" wire:confirm="{{ __('Tindakan ini tidak dapat diurungkan. Lanjutkan?') }}">
                        {{ __('Hapus') }}
                    </x-text-button>
                </div>
                <x-primary-button type="submit">
                    {{ __("Simpan") }}
                </x-primary-button>
            </div>
        @endcan
    </form>
    <x-spinner-bg wire:loading.class.remove="hidden"></x-spinner-bg>
    <x-spinner wire:loading.class.remove="hidden" class="hidden"></x-spinner>
</div>
