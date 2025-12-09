<?php

use Livewire\Volt\Component;
use App\Models\InsDwpDevice;
use App\Models\InsDwpStandardPV;
use Livewire\Attributes\Validate;

new class extends Component {
    #[Validate('required|string|max:255')]
    public string $setting_name = '';

    // Address fields
    #[Validate('required|numeric|min:0')]
    public $addr_min_th = '';

    #[Validate('required|numeric|min:0')]
    public $addr_max_th = '';

    #[Validate('required|numeric|min:0')]
    public $addr_min_s = '';

    #[Validate('required|numeric|min:0')]
    public $addr_max_s = '';

    // Standard value fields
    #[Validate('required|numeric|min:0')]
    public $min_th = '';

    #[Validate('required|numeric|min:0')]
    public $max_th = '';

    #[Validate('required|numeric|min:0')]
    public $min_s = '';

    #[Validate('required|numeric|min:0')]
    public $max_s = '';

    public $selectedDevice = null;
    public $selectedLine = null;
    public $selectedMachine = null;
    public $devices = [];
    public $availableLines = [];
    public $availableMachines = [];

    public function mount()
    {
        $this->devices = InsDwpDevice::active()->get();
    }

    public function updatedSelectedDevice($deviceId)
    {
        $this->selectedLine = null;
        $this->selectedMachine = null;
        $this->availableLines = [];
        $this->availableMachines = [];

        if ($deviceId) {
            $device = InsDwpDevice::find($deviceId);
            if ($device) {
                $this->availableLines = $device->getLines();
            }
        }
    }

    public function updatedSelectedLine($line)
    {
        $this->selectedMachine = null;
        $this->availableMachines = [];

        if ($this->selectedDevice && $line) {
            $device = InsDwpDevice::find($this->selectedDevice);
            if ($device) {
                $lineConfig = $device->getLineConfig($line);
                if ($lineConfig && isset($lineConfig['list_mechine'])) {
                    $this->availableMachines = collect($lineConfig['list_mechine'])->pluck('name')->toArray();
                }
            }
        }
    }

    public function updatedSelectedMachine($machineName)
    {
        if ($this->selectedDevice && $this->selectedLine && $machineName) {
            $device = InsDwpDevice::find($this->selectedDevice);
            $this->setting_name = "{$device->name}_{$this->selectedLine}_Machine_{$machineName}";
        }
    }

    public function rules()
    {
        return [
            'setting_name' => 'required|string|max:255',
            'addr_min_th' => 'required|numeric|min:0',
            'addr_max_th' => 'required|numeric|min:0|gte:addr_min_th',
            'addr_min_s' => 'required|numeric|min:0',
            'addr_max_s' => 'required|numeric|min:0|gte:addr_min_s',
            'min_th' => 'required|numeric|min:0',
            'max_th' => 'required|numeric|min:0|gte:min_th',
            'min_s' => 'required|numeric|min:0',
            'max_s' => 'required|numeric|min:0|gte:min_s',
        ];
    }

    public function messages()
    {
        return [
            'addr_max_th.gte' => __('Alamat maksimum Toe/Heel harus lebih besar atau sama dengan alamat minimum.'),
            'addr_max_s.gte' => __('Alamat maksimum Side harus lebih besar atau sama dengan alamat minimum.'),
            'max_th.gte' => __('Nilai maksimum Toe/Heel harus lebih besar atau sama dengan nilai minimum.'),
            'max_s.gte' => __('Nilai maksimum Side harus lebih besar atau sama dengan nilai minimum.'),
        ];
    }

    public function save()
    {
        $this->authorize('manage', InsDwpStandardPV::class);
        
        $validated = $this->validate();

        // Create standard with nested JSON structure
        InsDwpStandardPV::create([
            'setting_name' => $this->setting_name,
            'setting_value' => [
                'setting_address' => [
                    'addr_min_th' => (float)$this->addr_min_th,
                    'addr_max_th' => (float)$this->addr_max_th,
                    'addr_min_s' => (float)$this->addr_min_s,
                    'addr_max_s' => (float)$this->addr_max_s,
                ],
                'setting_std' => [
                    'min_th' => (float)$this->min_th,
                    'max_th' => (float)$this->max_th,
                    'min_s' => (float)$this->min_s,
                    'max_s' => (float)$this->max_s,
                ],
            ],
        ]);

        $this->dispatch('close');
        $this->dispatch('standard-created');
        
        session()->flash('message', __('Standar berhasil ditambahkan.'));
        
        return $this->redirect(request()->header('Referer'), navigate: true);
    }
}; ?>

<div class="p-6">
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __("Tambah Standar PV Baru") }}
        </h2>
        <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
            {{ __("Masukkan nama mesin dan nilai standar minimum serta maksimum.") }}
        </p>
    </div>

    <form wire:submit="save" class="space-y-6">
        <!-- Device Selection (Optional) -->
        <div>
            <x-input-label for="device" :value="__('Pilih Device (Opsional)')" />
            <select 
                wire:model.live="selectedDevice"
                id="device"
                class="mt-1 block w-full border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 focus:border-blue-500 dark:focus:border-blue-600 focus:ring-blue-500 dark:focus:ring-blue-600 rounded-md shadow-sm"
            >
                <option value="">{{ __('-- Pilih Device --') }}</option>
                @foreach($devices as $device)
                    <option value="{{ $device->id }}">{{ $device->name }} ({{ $device->ip_address }})</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                {{ __("Pilih device untuk mengisi nama otomatis berdasarkan konfigurasi mesin") }}
            </p>
        </div>

        <!-- Line Selection (if device selected) -->
        @if($selectedDevice && count($availableLines) > 0)
        <div>
            <x-input-label for="line" :value="__('Pilih Line')" />
            <select 
                wire:model.live="selectedLine"
                id="line"
                class="mt-1 block w-full border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 focus:border-blue-500 dark:focus:border-blue-600 focus:ring-blue-500 dark:focus:ring-blue-600 rounded-md shadow-sm"
            >
                <option value="">{{ __('-- Pilih Line --') }}</option>
                @foreach($availableLines as $line)
                    <option value="{{ $line }}">{{ $line }}</option>
                @endforeach
            </select>
        </div>
        @endif

        <!-- Machine Selection (if line selected) -->
        @if($selectedLine && count($availableMachines) > 0)
        <div>
            <x-input-label for="machine" :value="__('Pilih Mesin')" />
            <select 
                wire:model.live="selectedMachine"
                id="machine"
                class="mt-1 block w-full border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 focus:border-blue-500 dark:focus:border-blue-600 focus:ring-blue-500 dark:focus:ring-blue-600 rounded-md shadow-sm"
            >
                <option value="">{{ __('-- Pilih Mesin --') }}</option>
                @foreach($availableMachines as $machine)
                    <option value="{{ $machine }}">{{ __('Mesin') }} {{ $machine }}</option>
                @endforeach
            </select>
        </div>
        @endif

        <!-- Setting Name -->
        <div>
            <x-input-label for="setting_name" :value="__('Nama Mesin')" />
            <x-text-input 
                wire:model="setting_name" 
                id="setting_name" 
                class="mt-1 block w-full" 
                type="text" 
                placeholder="Contoh: Machine A, Press Line 1" 
                required 
                autofocus 
            />
            <x-input-error :messages="$errors->get('setting_name')" class="mt-2" />
            <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                {{ __("Akan diisi otomatis jika memilih device, line, dan mesin") }}
            </p>
        </div>

        <!-- Address Settings Section -->
        <div class="border-t border-neutral-200 dark:border-neutral-700 pt-4">
            <h3 class="text-lg font-medium text-neutral-900 dark:text-neutral-100 mb-4">
                {{ __('Pengaturan Alamat') }}
            </h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Toe/Heel Address -->
                <div class="space-y-4">
                    <h4 class="text-sm font-semibold text-neutral-700 dark:text-neutral-300">
                        {{ __('Toe/Heel') }}
                    </h4>
                    
                    <div>
                        <x-input-label for="addr_min_th" :value="__('Alamat Minimum (TH)')" />
                        <x-text-input 
                            wire:model="addr_min_th" 
                            id="addr_min_th" 
                            class="mt-1 block w-full" 
                            type="number" 
                            step="1"
                            placeholder="231" 
                            required 
                        />
                        <x-input-error :messages="$errors->get('addr_min_th')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="addr_max_th" :value="__('Alamat Maksimum (TH)')" />
                        <x-text-input 
                            wire:model="addr_max_th" 
                            id="addr_max_th" 
                            class="mt-1 block w-full" 
                            type="number" 
                            step="1"
                            placeholder="123" 
                            required 
                        />
                        <x-input-error :messages="$errors->get('addr_max_th')" class="mt-2" />
                    </div>
                </div>

                <!-- Side Address -->
                <div class="space-y-4">
                    <h4 class="text-sm font-semibold text-neutral-700 dark:text-neutral-300">
                        {{ __('Side') }}
                    </h4>
                    
                    <div>
                        <x-input-label for="addr_min_s" :value="__('Alamat Minimum (S)')" />
                        <x-text-input 
                            wire:model="addr_min_s" 
                            id="addr_min_s" 
                            class="mt-1 block w-full" 
                            type="number" 
                            step="1"
                            placeholder="231" 
                            required 
                        />
                        <x-input-error :messages="$errors->get('addr_min_s')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="addr_max_s" :value="__('Alamat Maksimum (S)')" />
                        <x-text-input 
                            wire:model="addr_max_s" 
                            id="addr_max_s" 
                            class="mt-1 block w-full" 
                            type="number" 
                            step="1"
                            placeholder="123" 
                            required 
                        />
                        <x-input-error :messages="$errors->get('addr_max_s')" class="mt-2" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Standard Value Settings Section -->
        <div class="border-t border-neutral-200 dark:border-neutral-700 pt-4">
            <h3 class="text-lg font-medium text-neutral-900 dark:text-neutral-100 mb-4">
                {{ __('Nilai Standar') }}
            </h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Toe/Heel Standard -->
                <div class="space-y-4">
                    <h4 class="text-sm font-semibold text-neutral-700 dark:text-neutral-300">
                        {{ __('Toe/Heel') }}
                    </h4>
                    
                    <div>
                        <x-input-label for="min_th" :value="__('Nilai Minimum (TH)')" />
                        <x-text-input 
                            wire:model="min_th" 
                            id="min_th" 
                            class="mt-1 block w-full" 
                            type="number" 
                            step="0.01"
                            placeholder="30" 
                            required 
                        />
                        <x-input-error :messages="$errors->get('min_th')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="max_th" :value="__('Nilai Maksimum (TH)')" />
                        <x-text-input 
                            wire:model="max_th" 
                            id="max_th" 
                            class="mt-1 block w-full" 
                            type="number" 
                            step="0.01"
                            placeholder="45" 
                            required 
                        />
                        <x-input-error :messages="$errors->get('max_th')" class="mt-2" />
                    </div>
                </div>

                <!-- Side Standard -->
                <div class="space-y-4">
                    <h4 class="text-sm font-semibold text-neutral-700 dark:text-neutral-300">
                        {{ __('Side') }}
                    </h4>
                    
                    <div>
                        <x-input-label for="min_s" :value="__('Nilai Minimum (S)')" />
                        <x-text-input 
                            wire:model="min_s" 
                            id="min_s" 
                            class="mt-1 block w-full" 
                            type="number" 
                            step="0.01"
                            placeholder="30" 
                            required 
                        />
                        <x-input-error :messages="$errors->get('min_s')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="max_s" :value="__('Nilai Maksimum (S)')" />
                        <x-text-input 
                            wire:model="max_s" 
                            id="max_s" 
                            class="mt-1 block w-full" 
                            type="number" 
                            step="0.01"
                            placeholder="45" 
                            required 
                        />
                        <x-input-error :messages="$errors->get('max_s')" class="mt-2" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Preview -->
        @if($addr_min_th && $addr_max_th && $addr_min_s && $addr_max_s && $min_th && $max_th && $min_s && $max_s)
            <div class="p-4 bg-neutral-50 dark:bg-neutral-700 rounded-lg">
                <p class="text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-2">
                    {{ __("Preview Format JSON:") }}
                </p>
                <pre class="text-xs bg-neutral-100 dark:bg-neutral-800 px-3 py-2 rounded overflow-x-auto"><code>{
  "setting_address": {
    "addr_min_th": {{ $addr_min_th }},
    "addr_max_th": {{ $addr_max_th }},
    "addr_min_s": {{ $addr_min_s }},
    "addr_max_s": {{ $addr_max_s }}
  },
  "setting_std": {
    "min_th": {{ $min_th }},
    "max_th": {{ $max_th }},
    "min_s": {{ $min_s }},
    "max_s": {{ $max_s }}
  }
}</code></pre>
            </div>
        @endif

        <!-- Actions -->
        <div class="flex items-center justify-end gap-x-3 mt-6">
            <x-secondary-button type="button" x-on:click="$dispatch('close')">
                {{ __("Batal") }}
            </x-secondary-button>
            <x-primary-button type="submit">
                {{ __("Simpan") }}
            </x-primary-button>
        </div>
    </form>
</div>
