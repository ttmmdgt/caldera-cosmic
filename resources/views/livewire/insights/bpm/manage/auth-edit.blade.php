<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use Illuminate\Validation\Rule;

use App\Models\User;
use App\Models\InsBpmAuth;
use Livewire\Attributes\Renderless;
use Illuminate\Support\Facades\Gate;

new class extends Component {
    public int $id;
    public string $user_name;
    public string $user_emp_id;
    public string $user_photo;
    public array $actions;

    public function rules()
    {
        return [
            "actions" => ["array"],
            "actions.*" => ["string"],
        ];
    }

    #[On("auth-edit")]
    public function loadAuth(int $id)
    {
        $auth = InsBpmAuth::find($id);
        if ($auth) {
            $this->id = $auth->id;
            $this->user_name = $auth->user->name;
            $this->user_emp_id = $auth->user->emp_id;
            $this->user_photo = $auth->user->photo ?? "";
            $this->actions = json_decode($auth->actions ?? "[]", true);
            $this->resetValidation();
        } else {
            $this->handleNotFound();
        }
    }

    public function with(): array
    {
        return [
            "is_superuser" => Gate::allows("superuser"),
        ];
    }

    public function save()
    {
        Gate::authorize("superuser");
        $this->validate();

        $auth = InsBpmAuth::find($this->id);
        if ($auth) {
            $auth->actions = json_encode($this->actions, true);
            $auth->update();

            $this->js('$dispatch("close")');
            $this->js('toast("' . __("Wewenang diperbarui") . '", { type: "success" })');
            $this->dispatch("updated");
        } else {
            $this->handleNotFound();
            $this->customReset();
        }
    }

    public function delete()
    {
        Gate::authorize("superuser");

        $auth = InsBpmAuth::find($this->id);
        if ($auth) {
            $auth->delete();

            $this->js('$dispatch("close")');
            $this->js('toast("' . __("Wewenang dicabut") . '", { type: "success" })');
            $this->dispatch("updated");
        } else {
            $this->handleNotFound();
        }
        $this->customReset();
    }

    public function customReset()
    {
        $this->reset(["id", "user_name", "user_emp_id", "user_photo", "user_photo", "actions"]);
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
                {{ __("Wewenang") }}
            </h2>
            <x-text-button type="button" x-on:click="$dispatch('close')"><i class="icon-x"></i></x-text-button>
        </div>
        <div class="grid grid-cols-1 gap-y-3 mt-3">
            <div wire:key="user-info" class="grid gap-3 grid-cols-1">
                <div class="flex p-4 border border-neutral-200 dark:border-neutral-700 rounded-lg">
                    <div>
                        <div class="w-8 h-8 my-auto mr-3 bg-neutral-200 dark:bg-neutral-700 rounded-full overflow-hidden">
                            @if ($user_photo)
                                <img class="w-full h-full object-cover dark:brightness-75" src="{{ "/storage/users/" . $user_photo }}" />
                            @else
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="block fill-current text-neutral-800 dark:text-neutral-200 opacity-25"
                                    viewBox="0 0 1000 1000"
                                    xmlns:v="https://vecta.io/nano"
                                >
                                    <path
                                        d="M621.4 609.1c71.3-41.8 119.5-119.2 119.5-207.6-.1-132.9-108.1-240.9-240.9-240.9s-240.8 108-240.8 240.8c0 88.5 48.2 165.8 119.5 207.6-147.2 50.1-253.3 188-253.3 350.4v3.8a26.63 26.63 0 0 0 26.7 26.7c14.8 0 26.7-12 26.7-26.7v-3.8c0-174.9 144.1-317.3 321.1-317.3S821 784.4 821 959.3v3.8a26.63 26.63 0 0 0 26.7 26.7c14.8 0 26.7-12 26.7-26.7v-3.8c.2-162.3-105.9-300.2-253-350.2zM312.7 401.4c0-103.3 84-187.3 187.3-187.3s187.3 84 187.3 187.3-84 187.3-187.3 187.3-187.3-84.1-187.3-187.3z"
                                    />
                                </svg>
                            @endif
                        </div>
                    </div>
                    <div class="truncate">
                        <div class="truncate">{{ $user_name ?? __("Pengguna") }}</div>
                        <div class="truncate text-xs text-neutral-400 dark:text-neutral-600">{{ $user_emp_id ?? __("Nomor karyawan") }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="grid grid-cols-1 gap-y-3 mt-6">
            <x-checkbox id="device-manage" :disabled="!$is_superuser" wire:model="actions" value="device-manage">{{ __("Mengelola perangkat BPM") }}</x-checkbox>
        </div>
        @can("superuser")
            <div class="mt-6 flex justify-between items-end">
                <div>
                    <x-text-button
                        type="button"
                        class="uppercase text-xs text-red-500"
                        wire:click="delete"
                        wire:confirm="{{ __('Tindakan ini tidak dapat diurungkan. Lanjutkan?') }}"
                    >
                        {{ __("Cabut") }}
                    </x-text-button>
                </div>
                <x-primary-button type="submit">
                    {{ __("Perbarui") }}
                </x-primary-button>
            </div>
        @endcan
    </form>
    <x-spinner-bg wire:loading.class.remove="hidden" wire:target.except="userq"></x-spinner-bg>
    <x-spinner wire:loading.class.remove="hidden" wire:target.except="userq" class="hidden"></x-spinner>
</div>
