<?php

use Livewire\Volt\Component;
use App\Models\Project;

new class extends Component {
    public ?Project $project = null;
    public string $name = '';
    public string $project_group = '';
    public string $ip = '';
    public int $timeout = 30;
    public string $type = 'http';
    public bool $is_active = true;

    public string $location = '';
    // Modbus Config Fields
    public ?int $modbus_port = null;
    public ?int $modbus_unit_id = null;
    public ?int $modbus_quantity = null;
    public ?int $modbus_start_address = null;

    #[\Livewire\Attributes\On('project-edit-load')]
    public function loadProject($id)
    {
        $this->project = Project::find($id);
        
        if ($this->project) {
            $this->name = $this->project->name;
            $this->project_group = $this->project->project_group;
            $this->ip = $this->project->ip;
            $this->timeout = $this->project->timeout;
            $this->type = $this->project->type;
            $this->is_active = $this->project->is_active;
            $this->location = $this->project->location;
            // Load Modbus Config
            if ($this->project->modbus_config) {
                $config = is_string($this->project->modbus_config) 
                    ? json_decode($this->project->modbus_config, true) 
                    : $this->project->modbus_config;
                    
                $this->modbus_port = $config['port'] ?? null;
                $this->modbus_unit_id = $config['unit_id'] ?? null;
                $this->modbus_quantity = $config['quantity'] ?? null;
                $this->modbus_start_address = $config['start_address'] ?? null;
            }
        }
    }

    public function save()
    {
        $this->validate([
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
            'location' => 'nullable|string|max:255',
        ]);

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

        $this->project->update([
            'name' => $this->name,
            'project_group' => $this->project_group,
            'ip' => $this->ip,
            'timeout' => $this->timeout,
            'type' => $this->type,
            'is_active' => $this->is_active,
            'modbus_config' => $modbusConfig,
            'location' => $this->location,
        ]);

        $this->dispatch('close-modal', 'project-edit');
        $this->dispatch('project-updated');
        
        session()->flash('message', 'Project updated successfully!');
    }

    public function deleteProject()
    {
        $this->project->delete();
        $this->dispatch('close-modal', 'project-edit');
        $this->dispatch('project-deleted');
        session()->flash('message', 'Project deleted successfully!');
    }

    public function closeModal()
    {
        $this->dispatch('close-modal', 'project-edit');
        $this->reset();
    }
};

?>

<x-modal name="project-edit" :show="false" maxWidth="2xl">
    <div class="p-6">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-semibold text-neutral-800 dark:text-neutral-200">
                {{ __('Edit Project') }}
            </h2>
            <button type="button" @click="$dispatch('close-modal', 'project-edit')" class="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">
                <i class="icon-x text-xl"></i>
            </button>
        </div>

        <form wire:submit="save">
            <div class="space-y-4">
                <!-- Name -->
                <div>
                    <label for="name" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                        {{ __('Project Name') }} <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="text" 
                        id="name"
                        wire:model="name"
                        class="form-input w-full text-sm border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 dark:text-neutral-300 focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600 rounded-md shadow-sm"
                        required
                    >
                    @error('name')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Project Group -->
                <div>
                    <label for="project_group" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                        {{ __('Project Group') }} <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="text" 
                        id="project_group"
                        wire:model="project_group"
                        class="form-input w-full text-sm border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 dark:text-neutral-300 focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600 rounded-md shadow-sm"
                        required
                    >
                    @error('project_group')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- IP Address -->
                <div>
                    <label for="ip" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                        {{ __('IP Address') }} <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="text" 
                        id="ip"
                        wire:model="ip"
                        class="form-input w-full text-sm border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 dark:text-neutral-300 focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600 rounded-md shadow-sm"
                        placeholder="192.168.1.1"
                        required
                    >
                    @error('ip')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <!-- Timeout -->
                    <div>
                        <label for="timeout" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                            {{ __('Timeout (seconds)') }} <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="number" 
                            id="timeout"
                            wire:model="timeout"
                            min="1"
                            class="form-input w-full text-sm border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 dark:text-neutral-300 focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600 rounded-md shadow-sm"
                            required
                        >
                        @error('timeout')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Type -->
                    <div>
                        <label for="type" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                            {{ __('Type') }} <span class="text-red-500">*</span>
                        </label>
                        <select 
                            id="type"
                            wire:model="type"
                            class="form-select w-full text-sm border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 dark:text-neutral-300 focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600 rounded-md shadow-sm"
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
                                class="form-input w-full text-sm border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 dark:text-neutral-300 focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600 rounded-md shadow-sm"
                            >
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
                                class="form-input w-full text-sm border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 dark:text-neutral-300 focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600 rounded-md shadow-sm"
                            >
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
                                class="form-input w-full text-sm border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 dark:text-neutral-300 focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600 rounded-md shadow-sm"
                            >
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
                                class="form-input w-full text-sm border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 dark:text-neutral-300 focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600 rounded-md shadow-sm"
                            >
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
                        class="form-checkbox h-4 w-4 text-caldy-600 border-neutral-300 dark:border-neutral-700 rounded focus:ring-caldy-500 dark:focus:ring-caldy-600"
                    >
                    <label for="is_active" class="ml-2 block text-sm text-neutral-700 dark:text-neutral-300">
                        {{ __('Active') }}
                    </label>
                </div>

                <!-- Location -->
                <div>
                    <label for="location" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                        {{ __('Location') }}
                    </label>
                    <input type="text" id="location" wire:model="location" class="form-input w-full text-sm border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 dark:text-neutral-300 focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600 rounded-md shadow-sm">
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="mt-6 flex justify-end gap-3">
                <x-text-button type="button" wire:click="deleteProject" class="text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 text-sm bg-neutral-100 dark:bg-neutral-700 rounded-md p-2" title="Delete">
                    {{ __('Delete Project') }}
                </x-text-button>
                <x-secondary-button type="button" wire:click="closeModal">
                    {{ __('Cancel') }}
                </x-secondary-button>
                <x-primary-button type="submit">
                    {{ __('Save Changes') }}
                </x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
