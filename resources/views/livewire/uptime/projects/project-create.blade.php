<?php

use Livewire\Volt\Component;
use App\Models\Project;

new class extends Component {
    public string $name = '';
    public string $project_group = '';
    public string $ip = '';
    public int $timeout = 30;
    public string $type = 'http';
    public bool $is_active = true;
    
    // Modbus Config Fields
    public ?int $modbus_port = null;
    public ?int $modbus_unit_id = null;
    public ?int $modbus_quantity = null;
    public ?int $modbus_start_address = null;

    protected $listeners = ['modal-closed' => 'resetForm'];

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'project_group' => 'required|string|max:255',
            'ip' => 'required|string|max:255',
            'timeout' => 'required|integer|min:1',
            'type' => 'required|string|max:50',
            'is_active' => 'boolean',
            'modbus_port' => 'nullable|integer|min:1|max:65535',
            'modbus_unit_id' => 'nullable|integer|min:0|max:255',
            'modbus_quantity' => 'nullable|integer|min:1',
            'modbus_start_address' => 'nullable|integer|min:0',
        ];
    }

    public function save()
    {
        $validated = $this->validate();

        // Build Modbus Config JSON
        $modbusConfig = null;
        if ($this->modbus_port || $this->modbus_unit_id || $this->modbus_quantity || $this->modbus_start_address) {
            $modbusConfig = [
                'port' => $this->modbus_port,
                'unit_id' => $this->modbus_unit_id,
                'quantity' => $this->modbus_quantity,
                'start_address' => $this->modbus_start_address,
            ];
        }

        Project::create([
            'name' => $this->name,
            'project_group' => $this->project_group,
            'ip' => $this->ip,
            'timeout' => $this->timeout,
            'type' => $this->type,
            'is_active' => $this->is_active,
            'modbus_config' => $modbusConfig,
        ]);

        $this->dispatch('close-modal', 'project-create');
        $this->dispatch('project-created');
        $this->resetForm();

        session()->flash('message', 'Project created successfully.');
    }

    public function resetForm()
    {
        $this->reset([
            'name',
            'project_group',
            'ip',
            'timeout',
            'type',
            'is_active',
            'modbus_port',
            'modbus_unit_id',
            'modbus_quantity',
            'modbus_start_address',
        ]);
        $this->resetValidation();
    }
};

?>

<x-modal name="project-create" maxWidth="2xl" focusable>
    <div class="p-6">
        <h2 class="text-lg font-medium text-neutral-900 dark:text-neutral-100">
            {{ __('Add New Project') }}
        </h2>

        <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
            {{ __('Create a new project for monitoring.') }}
        </p>

        <form wire:submit="save" class="mt-6 space-y-6">
            <!-- Project Name -->
            <div>
                <label for="name" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                    {{ __('Project Name') }} <span class="text-red-500">*</span>
                </label>
                <input 
                    type="text" 
                    id="name" 
                    wire:model="name"
                    class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 shadow-sm focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600"
                    placeholder="Enter project name"
                    required
                />
                @error('name')
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <!-- Project Group -->
            <div>
                <label for="project_group" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                    {{ __('Project Group') }} <span class="text-red-500">*</span>
                </label>
                <input 
                    type="text" 
                    id="project_group" 
                    wire:model="project_group"
                    class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 shadow-sm focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600"
                    placeholder="Enter project group"
                    required
                />
                @error('project_group')
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <!-- IP Address -->
            <div>
                <label for="ip" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                    {{ __('IP Address') }} <span class="text-red-500">*</span>
                </label>
                <input 
                    type="text" 
                    id="ip" 
                    wire:model="ip"
                    class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 shadow-sm focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600"
                    placeholder="192.168.1.1"
                    required
                />
                @error('ip')
                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <!-- Timeout -->
                <div>
                    <label for="timeout" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                        {{ __('Timeout (seconds)') }} <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="number" 
                        id="timeout" 
                        wire:model="timeout"
                        min="1"
                        class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 shadow-sm focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600"
                        required
                    />
                    @error('timeout')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Type -->
                <div>
                    <label for="type" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300">
                        {{ __('Type') }} <span class="text-red-500">*</span>
                    </label>
                    <select 
                        id="type" 
                        wire:model.live="type"
                        class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 shadow-sm focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600"
                        required
                    >
                        <option value="http">HTTP</option>
                        <option value="https">HTTPS</option>
                        <option value="tcp">TCP</option>
                        <option value="ping">Ping</option>
                        <option value="modbus">Modbus</option>
                    </select>
                    @error('type')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Modbus Config -->
            <div x-show="$wire.type === 'modbus'" class="border border-neutral-300 dark:border-neutral-700 rounded-lg p-4 space-y-4">
                <h3 class="text-sm font-semibold text-neutral-700 dark:text-neutral-300 mb-2">
                    {{ __('Modbus Configuration') }}
                </h3>
                
                <div class="grid grid-cols-2 gap-4">
                    <!-- Port -->
                    <div>
                        <label for="modbus_port" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                            {{ __('Port') }}
                        </label>
                        <input 
                            type="number" 
                            id="modbus_port"
                            wire:model="modbus_port"
                            min="1"
                            max="65535"
                            placeholder="502"
                            class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 shadow-sm focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600"
                        />
                        @error('modbus_port')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Unit ID -->
                    <div>
                        <label for="modbus_unit_id" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                            {{ __('Unit ID') }}
                        </label>
                        <input 
                            type="number" 
                            id="modbus_unit_id"
                            wire:model="modbus_unit_id"
                            min="0"
                            max="255"
                            placeholder="1"
                            class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 shadow-sm focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600"
                        />
                        @error('modbus_unit_id')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Quantity -->
                    <div>
                        <label for="modbus_quantity" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                            {{ __('Quantity') }}
                        </label>
                        <input 
                            type="number" 
                            id="modbus_quantity"
                            wire:model="modbus_quantity"
                            min="1"
                            placeholder="1"
                            class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 shadow-sm focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600"
                        />
                        @error('modbus_quantity')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Start Address -->
                    <div>
                        <label for="modbus_start_address" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                            {{ __('Start Address') }}
                        </label>
                        <input 
                            type="number" 
                            id="modbus_start_address"
                            wire:model="modbus_start_address"
                            min="0"
                            placeholder="0"
                            class="mt-1 block w-full rounded-md border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 shadow-sm focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600"
                        />
                        @error('modbus_start_address')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <!-- Is Active -->
            <div class="flex items-center">
                <input 
                    type="checkbox" 
                    id="is_active" 
                    wire:model="is_active"
                    class="rounded border-neutral-300 dark:border-neutral-700 text-caldy-600 shadow-sm focus:ring-caldy-500 dark:focus:ring-caldy-600"
                />
                <label for="is_active" class="ml-2 block text-sm text-neutral-700 dark:text-neutral-300">
                    {{ __('Is Active') }}
                </label>
            </div>

            <!-- Actions -->
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'project-create')">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-primary-button type="submit">
                    {{ __('Create Project') }}
                </x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
