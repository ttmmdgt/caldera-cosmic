<?php

use Livewire\Volt\Component;
use App\Models\InsRubberColor;
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
            "name" => ["required", "min:1", "max:140", Rule::unique("ins_rubber_colors", "name")->ignore($this->id ?? null)],
            "description" => ["nullable", "string", "max:255"],
        ];
    }

    #[On("color-edit")]
    public function loadColor(int $id)
    {
        $color = InsRubberColor::find($id);
        if ($color) {
            $this->id = $color->id;
            $this->name = $color->name;
            $this->description = $color->description ?? "";
            $this->resetValidation();
        } else {
            $this->handleNotFound();
        }
    }

    public function save()
    {
        $color = InsRubberColor::find($this->id);

        $this->name = strtoupper(trim($this->name));
        $validated = $this->validate();

        if ($color) {
            Gate::authorize("manage", $color);

            $color->update([
                "name" => $validated["name"],
                "description" => $validated["description"] ?? null,
            ]);

            $this->js('$dispatch("close")');
            $this->js('toast("' . __("Warna diperbarui") . '", { type: "success" })');
            $this->dispatch("updated");
        } else {
            $this->handleNotFound();
            $this->customReset();
        }
    }

    public function delete()
    {
        $color = InsRubberColor::find($this->id);

        if ($color) {
            Gate::authorize("manage", $color);

            try {
                $color->delete();
                $this->js('$dispatch("close")');
                $this->js('toast("' . __("Warna dihapus") . '", { type: "success" })');
                $this->dispatch("updated");
                $this->customReset();
            } catch (\Exception $e) {
                $this->js('toast("' . __("Gagal menghapus. Warna masih digunakan.") . '", { type: "danger" })');
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
                {{ __("Warna") }}
            </h2>
            <x-text-button type="button" x-on:click="$dispatch('close')"><i class="icon-x"></i></x-text-button>
        </div>
        <div class="mt-6">
            <label for="color-name" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Nama") }}</label>
            <x-text-input id="color-name" wire:model="name" type="text" :disabled="Gate::denies('manage', InsRubberColor::class)" />
            @error("name")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
        </div>
        <div class="mt-6">
            <label for="color-description" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Deskripsi") }}</label>
            <x-text-input id="color-description" wire:model="description" type="text" :disabled="Gate::denies('manage', InsRubberColor::class)" />
            @error("description")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
        </div>

        @can("manage", InsRubberColor::class)
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
