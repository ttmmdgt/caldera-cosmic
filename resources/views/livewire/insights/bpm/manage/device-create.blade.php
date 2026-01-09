<?php

use Livewire\Volt\Component;
use App\Models\InsBpmDevice;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;

new class extends Component {
    public string $name = "";
    public string $line = "";
    public string $ip_address = "";
    public bool $is_active = true;
    public array $machines = [];
    public int $addr_reset = 0;

    public function mount()
    {
        // Initialize with one machine by default
        $this->addMachine();
    }

    public function rules()
    {
        $rules = [
            "name" => ["required", "string", "min:1", "max:50"],
            "line" => ["required", "string", "min:1", "max:50", Rule::unique('ins_bpm_devices', 'line')],
            "ip_address" => ["required", "ip", Rule::unique('ins_bpm_devices', 'ip_address')],
            "is_active" => ["boolean"],
            "addr_reset" => ["required", "integer", "min:0"],
            "machines" => ["required", "array", "min:1"],
            "machines.*.name" => ["required", "string", "max:50"],
            "machines.*.addr_hot" => ["required", "integer", "min:0"],
            "machines.*.addr_cold" => ["required", "integer", "min:0"],
        ];

        return $rules;
    }

    public function addMachine()
    {
        $this->machines[] = [
            "name" => "",
            "addr_hot" => "",
            "addr_cold" => "",
        ];
    }

    public function removeMachine($index)
    {
        unset($this->machines[$index]);
        $this->machines = array_values($this->machines); // Re-index array
    }

    public function save()
    {
        $device = new InsBpmDevice();
        Gate::authorize("manage", $device);

        $this->name = trim($this->name);
        $validated = $this->validate();

        // Build config JSON structure
        $config = [
            "list_mechine" => array_map(function($machine) {
                return [
                    "name" => $machine["name"],
                    "addr_hot" => (int)$machine["addr_hot"],
                    "addr_cold" => (int)$machine["addr_cold"],
                ];
            }, $validated["machines"]),
            "addr_reset" => (int)$this->addr_reset,
        ];
        $device->fill([
            "name" => $validated["name"],
            "line" => $validated["line"],
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
        $this->reset(["name", "line", "ip_address", "is_active", "machines", "addr_reset"]);
        $this->is_active = true;
        $this->addMachine(); // Add one machine by default
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
            <label for="device-line" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Line") }}</label>
            <x-text-input id="device-line" wire:model="line" type="text" />
            @error("line")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
        </div>
        <div class="mt-6">
            <label for="device-ip" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("IP Address") }}</label>
            <x-text-input id="device-ip" wire:model="ip_address" type="text" placeholder="192.168.1.100" />
            @error("ip_address")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
        </div>
        <div class="mt-6">
            <label for="addr_reset" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Reset Address") }}</label>
            <x-text-input id="addr_reset" wire:model="addr_reset" type="number" placeholder="0" />
            @error("addr_reset")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
        </div>
        <!-- Machine Configuration Section -->
        <div class="mt-6">
            <div class="flex justify-between items-center mb-3">
                <label class="block px-3 uppercase text-xs text-neutral-500">{{ __("Konfigurasi Mesin") }}</label>
                <button type="button" wire:click="addMachine" class="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                    <i class="icon-plus"></i> {{ __("Tambah Mesin") }}
                </button>
            </div>
            
            @foreach($machines as $index => $machine)
                <div class="mb-4 p-4 border border-neutral-200 dark:border-neutral-700 rounded-lg">
                    <div class="flex justify-between items-start mb-3">
                        <h4 class="text-sm font-medium text-neutral-700 dark:text-neutral-300">{{ __("Mesin") }} #{{ $index + 1 }}</h4>
                        @if(count($machines) > 1)
                            <button type="button" wire:click="removeMachine({{ $index }})" class="text-red-600 hover:text-red-800 dark:text-red-400">
                                <i class="icon-trash"></i>
                            </button>
                        @endif
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="machine-name-{{ $index }}" class="block mb-1 text-xs text-neutral-500">{{ __("Nama Mesin") }}</label>
                            <x-text-input id="machine-name-{{ $index }}" wire:model="machines.{{ $index }}.name" type="text" placeholder="1" />
                            @error("machines.{$index}.name")
                                <x-input-error messages="{{ $message }}" class="mt-1" />
                            @enderror
                        </div>
                        
                        <div>
                            <label for="machine-hot-{{ $index }}" class="block mb-1 text-xs text-neutral-500">{{ __("Addr Hot") }}</label>
                            <x-text-input id="machine-hot-{{ $index }}" wire:model="machines.{{ $index }}.addr_hot" type="number" placeholder="199" />
                            @error("machines.{$index}.addr_hot")
                                <x-input-error messages="{{ $message }}" class="mt-1" />
                            @enderror
                        </div>
                        
                        <div>
                            <label for="machine-cold-{{ $index }}" class="block mb-1 text-xs text-neutral-500">{{ __("Addr Cold") }}</label>
                            <x-text-input id="machine-cold-{{ $index }}" wire:model="machines.{{ $index }}.addr_cold" type="number" placeholder="201" />
                            @error("machines.{$index}.addr_cold")
                                <x-input-error messages="{{ $message }}" class="mt-1" />
                            @enderror
                        </div>
                    </div>
                </div>
            @endforeach
            
            @error("machines")
                <x-input-error messages="{{ $message }}" class="px-3 mt-2" />
            @enderror
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
