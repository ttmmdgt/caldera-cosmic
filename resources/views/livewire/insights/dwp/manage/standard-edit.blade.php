<?php

use Livewire\Volt\Component;
use App\Models\InsDwpStandardPV;
use App\Models\InsDwpDevice;
use Livewire\Attributes\Validate;
use Livewire\Attributes\On;
use ModbusTcpClient\Network\NonBlockingClient;
use ModbusTcpClient\Composer\Read\ReadRegistersBuilder;

new class extends Component {
    public ?InsDwpStandardPV $standard = null;

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

    #[On('standard-edit')]
    public function loadStandard($id)
    {
        $this->standard = InsDwpStandardPV::findOrFail($id);
        $this->setting_name = $this->standard->setting_name;
        
        // Load values from nested JSON structure
        $settingValue = $this->standard->setting_value;
        
        if (is_array($settingValue)) {
            // New nested structure with toe/heel and side
            $this->addr_min_th = $settingValue['setting_address']['addr_min_th'] ?? '';
            $this->addr_max_th = $settingValue['setting_address']['addr_max_th'] ?? '';
            $this->addr_min_s = $settingValue['setting_address']['addr_min_s'] ?? '';
            $this->addr_max_s = $settingValue['setting_address']['addr_max_s'] ?? '';
            $this->min_th = $settingValue['setting_std']['min_th'] ?? '';
            $this->max_th = $settingValue['setting_std']['max_th'] ?? '';
            $this->min_s = $settingValue['setting_std']['min_s'] ?? '';
            $this->max_s = $settingValue['setting_std']['max_s'] ?? '';
        }
    }

    // Get standard on HMI (use modbus communication to get data from HMI)
    public function getDataFromHMI()
    {
        try {
            // Get the first active device (you can modify this to select specific device)
            $device = InsDwpDevice::where('is_active', true)->first();
            
            if (!$device) {
                session()->flash('error', __('Tidak ada perangkat aktif yang ditemukan.'));
                return;
            }

            // Get Modbus configuration from config file
            $modbusPort = config('services.modbus.dwp.port', 503);
            $modbusUnitId = config('services.modbus.dwp.unit_id', 1);
            $modbusTimeout = config('services.modbus.dwp.timeout', 3);
            $valueDivisor = config('services.modbus.dwp.value_divisor', 10);

            // Get addresses from current standard configuration
            $addresses = [
                'addr_min_th' => (int)$this->addr_min_th,
                'addr_max_th' => (int)$this->addr_max_th,
                'addr_min_s' => (int)$this->addr_min_s,
                'addr_max_s' => (int)$this->addr_max_s,
            ];

            // Validate addresses are set
            if (empty($addresses['addr_min_th']) || empty($addresses['addr_max_th']) || 
                empty($addresses['addr_min_s']) || empty($addresses['addr_max_s'])) {
                session()->flash('error', __('Alamat Modbus harus diisi terlebih dahulu.'));
                return;
            }

            // Build Modbus request for holding registers (RW - Read/Write)
            // Using Function Code 03 (Read Holding Registers) for RW addresses
            $fc3 = ReadRegistersBuilder::newReadHoldingRegisters(
                "tcp://{$device->ip_address}:{$modbusPort}",
                $modbusUnitId
            );

            // Add all addresses to the request
            foreach ($addresses as $key => $addr) {
                $fc3->int16($addr, $key);
            }

            // Execute Modbus request with timeout
            $response = (new NonBlockingClient(['readTimeoutSec' => $modbusTimeout]))->sendRequests($fc3->build());
            $data = $response->getData();
            // Check if we got data
            if (empty($data)) {
                session()->flash('error', __('Tidak ada data yang diterima dari HMI.'));
                return;
            }

            // Update form fields with fetched data
            // Convert raw int16 values to proper decimal format
            $this->min_th = isset($data['addr_min_th']) ? $data['addr_min_th'] / $valueDivisor : $this->min_th;
            $this->max_th = isset($data['addr_max_th']) ? $data['addr_max_th'] / $valueDivisor : $this->max_th;
            $this->min_s = isset($data['addr_min_s']) ? $data['addr_min_s'] / $valueDivisor : $this->min_s;
            $this->max_s = isset($data['addr_max_s']) ? $data['addr_max_s'] / $valueDivisor : $this->max_s;

            session()->flash('message', __('Data berhasil diambil dari HMI.'));
            
        } catch (\Exception $e) {
            session()->flash('error', __('Gagal mengambil data dari HMI: ') . $e->getMessage());
        }
    }

    public function sendDataToHMI()
    {
        try {
            // Validate form data before sending
            $validated = $this->validate();

            // Get the first active device
            $device = InsDwpDevice::where('is_active', true)->first();
            
            if (!$device) {
                session()->flash('error', __('Tidak ada perangkat aktif yang ditemukan.'));
                return;
            }

            // Get Modbus configuration from config file
            $modbusPort = config('services.modbus.dwp.port', 503);
            $modbusUnitId = config('services.modbus.dwp.unit_id', 1);
            $modbusTimeout = config('services.modbus.dwp.timeout', 3);
            $valueDivisor = config('services.modbus.dwp.value_divisor', 10);

            // Get addresses from current standard configuration
            $addresses = [
                'addr_min_th' => (int)$this->addr_min_th,
                'addr_max_th' => (int)$this->addr_max_th,
                'addr_min_s' => (int)$this->addr_min_s,
                'addr_max_s' => (int)$this->addr_max_s,
            ];

            // Validate addresses are set
            if (empty($addresses['addr_min_th']) || empty($addresses['addr_max_th']) || 
                empty($addresses['addr_min_s']) || empty($addresses['addr_max_s'])) {
                session()->flash('error', __('Alamat Modbus harus diisi terlebih dahulu.'));
                return;
            }

            // Prepare values to write (multiply by divisor to convert to int16 format)
            $values = [
                'addr_min_th' => (int)($this->min_th * $valueDivisor),
                'addr_max_th' => (int)($this->max_th * $valueDivisor),
                'addr_min_s' => (int)($this->min_s * $valueDivisor),
                'addr_max_s' => (int)($this->max_s * $valueDivisor),
            ];

            // Write each value to HMI using Modbus Function Code 06 (Write Single Register)
            $client = new NonBlockingClient(['readTimeoutSec' => $modbusTimeout]);
            $writeRequests = [];

            foreach ($addresses as $key => $addr) {
                $packet = \ModbusTcpClient\Packet\ModbusFunction\WriteMultipleRegistersRequest::build(
                    $modbusUnitId,
                    $addr,
                    [\ModbusTcpClient\Utils\Types::toInt16($values[$key])]
                );
                
                $writeRequests[] = new \ModbusTcpClient\Network\BinaryStreamConnection(
                    "tcp://{$device->ip_address}:{$modbusPort}",
                    $packet
                );
            }

            // Send all write requests
            $responses = $client->sendRequests($writeRequests);

            // Check if all writes were successful
            $errorCount = 0;
            foreach ($responses as $response) {
                if ($response->getErrorMessage()) {
                    $errorCount++;
                }
            }

            if ($errorCount > 0) {
                session()->flash('error', __('Beberapa data gagal dikirim ke HMI. Silakan coba lagi.'));
                return;
            }

            session()->flash('message', __('Data berhasil dikirim ke HMI.'));
            
        } catch (\Exception $e) {
            session()->flash('error', __('Gagal mengirim data ke HMI: ') . $e->getMessage());
        }
    }

    public function update()
    {
        $this->authorize('manage', InsDwpStandardPV::class);
        
        $validated = $this->validate();

        // Update standard with nested JSON structure
        $this->standard->update([
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
        $this->dispatch('standard-updated');
        
        session()->flash('message', __('Standar berhasil diperbarui.'));
        
        return $this->redirect(request()->header('Referer'), navigate: true);
    }

    public function delete()
    {
        $this->authorize('manage', InsDwpStandardPV::class);
        
        $this->standard->delete();

        $this->dispatch('close');
        $this->dispatch('standard-deleted');
        
        session()->flash('message', __('Standar berhasil dihapus.'));
        
        return $this->redirect(request()->header('Referer'), navigate: true);
    }
}; ?>

<div class="p-6">
    @if($standard)
        <div class="mb-6">
            <h2 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                {{ __("Edit Standar PV") }}
            </h2>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                {{ __("Perbarui nilai standar minimum dan maksimum untuk mesin ini.") }}
            </p>
        </div>

        <!-- Flash Messages -->
        @if (session()->has('message'))
            <div class="mb-4 p-4 bg-green-100 dark:bg-green-900 border border-green-400 dark:border-green-600 text-green-700 dark:text-green-200 rounded-lg">
                {{ session('message') }}
            </div>
        @endif
        
        @if (session()->has('error'))
            <div class="mb-4 p-4 bg-red-100 dark:bg-red-900 border border-red-400 dark:border-red-600 text-red-700 dark:text-red-200 rounded-lg">
                {{ session('error') }}
            </div>
        @endif

        <form wire:submit="update" class="space-y-6">
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
                <div class="flex items-center gap-x-3 mt-2">
                    <!-- button Get Data From HMI -->
                    <x-secondary-button 
                        type="button"
                        wire:click="getDataFromHMI"
                        wire:loading.attr="disabled"
                    >
                        <span wire:loading.remove wire:target="getDataFromHMI">
                            {{ __("Get Data From HMI") }}
                        </span>
                        <span wire:loading wire:target="getDataFromHMI">
                            {{ __("Mengambil data...") }}
                        </span>
                    </x-secondary-button>

                    <!-- button Send Data To HMI -->
                    <x-secondary-button 
                        type="button"
                        wire:click="sendDataToHMI"
                        wire:loading.attr="disabled"
                        wire:confirm="{{ __('Apakah Anda yakin ingin mengirim data ini ke HMI?') }}"
                    >
                        <span wire:loading.remove wire:target="sendDataToHMI">
                            {{ __("Send Data To HMI") }}
                        </span>
                        <span wire:loading wire:target="sendDataToHMI">
                            {{ __("Mengirim data...") }}
                        </span>
                    </x-secondary-button>
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
            <div class="flex items-center justify-between mt-6">
                <x-danger-button 
                    type="button" 
                    wire:click="delete"
                    wire:confirm="{{ __('Apakah Anda yakin ingin menghapus standar ini?') }}"
                >
                    {{ __("Hapus") }}
                </x-danger-button>
                
                <div class="flex items-center gap-x-3">
                    <x-secondary-button type="button" x-on:click="$dispatch('close')">
                        {{ __("Batal") }}
                    </x-secondary-button>
                    <x-primary-button type="submit">
                        {{ __("Perbarui") }}
                    </x-primary-button>
                </div>
            </div>
        </form>
    @else
        <div class="text-center py-12">
            <p class="text-neutral-500">{{ __("Memuat...") }}</p>
        </div>
    @endif
</div>
