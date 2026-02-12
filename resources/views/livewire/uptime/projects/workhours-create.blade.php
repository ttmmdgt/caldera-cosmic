<?php

use Livewire\Volt\Component;
use App\Models\ProjectWorkingHour;
use App\Models\Project;
use App\Models\Shift;

new class extends Component {
    public ?string $project_group = null;
    public ?int $shift_id = null;
    public ?string $work_start_time = null;
    public ?string $work_end_time = null;
    public bool $is_working_day = true;
    public array $break_times = [];
    
    // For adding new break time
    public string $break_start = '';
    public string $break_end = '';

    #[\Livewire\Attributes\On('workhour-create-load')]
    public function loadProjectGroup($projectGroup = null)
    {
        if ($projectGroup) {
            $this->project_group = $projectGroup;
        }
    }

    public function addBreakTime()
    {
        if ($this->break_start && $this->break_end) {
            $this->break_times[] = [
                'start' => $this->break_start,
                'end' => $this->break_end
            ];
            
            $this->break_start = '';
            $this->break_end = '';
        }
    }

    public function removeBreakTime($index)
    {
        unset($this->break_times[$index]);
        $this->break_times = array_values($this->break_times);
    }

    public function save()
    {
        $validated = $this->validate([
            'project_group' => 'required|string',
            'shift_id' => 'required|exists:shifts,id',
            'work_start_time' => 'required|date_format:H:i',
            'work_end_time' => 'required|date_format:H:i',
            'is_working_day' => 'boolean',
            'break_times' => 'nullable|array',
            'break_times.*.start' => 'required|date_format:H:i',
            'break_times.*.end' => 'required|date_format:H:i',
        ]);

        try {
            // Check if this combination already exists
            $exists = ProjectWorkingHour::where('project_group', $this->project_group)
                ->where('shift_id', $this->shift_id)
                ->exists();

            if ($exists) {
                session()->flash('error', 'Working hours for this project group and shift combination already exists!');
                return;
            }

            ProjectWorkingHour::create([
                'project_group' => $this->project_group,
                'shift_id' => $this->shift_id,
                'work_start_time' => $this->work_start_time,
                'work_end_time' => $this->work_end_time,
                'is_working_day' => $this->is_working_day,
                'break_times' => $this->break_times,
            ]);

            $this->dispatch('close-modal', 'workhour-create');
            $this->dispatch('workhour-updated');
            $this->reset();
            
            session()->flash('message', 'Working hour created successfully!');
        } catch (\Exception $e) {
            session()->flash('error', 'Failed to create working hour: ' . $e->getMessage());
        }
    }

    public function closeModal()
    {
        $this->dispatch('close-modal', 'workhour-create');
        $this->reset();
    }

    public function mount()
    {
        // Initialize component
    }

    public function with()
    {
        return [
            'projectGroups' => Project::distinct()->orderBy('project_group')->pluck('project_group'),
            'shifts' => Shift::active()->ordered()->get(),
        ];
    }
};

?>

<x-modal name="workhour-create" :show="false" maxWidth="3xl">
    <div class="p-6">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h2 class="text-xl font-semibold text-neutral-800 dark:text-neutral-200">
                    {{ __('Add Working Hours') }}
                </h2>
                <p class="text-sm text-neutral-500 mt-1">
                    Create new working hours for a project group
                </p>
            </div>
            <button type="button" @click="$dispatch('close-modal', 'workhour-create')" class="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">
                <i class="icon-x text-xl"></i>
            </button>
        </div>

        <form wire:submit="save">
            <div class="space-y-4">
                <!-- Project Group Selection -->
                <div>
                    <label for="create_project_group" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                        {{ __('Project Group') }} <span class="text-red-500">*</span>
                    </label>
                    <select 
                        id="create_project_group"
                        wire:model.live="project_group"
                        class="form-select w-full text-sm border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 dark:text-neutral-300 focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600 rounded-md shadow-sm"
                        required
                    >
                        <option value="">{{ __('Select Project Group') }}</option>
                        @foreach($projectGroups as $group)
                            <option value="{{ $group }}">{{ $group }}</option>
                        @endforeach
                    </select>
                    @error('project_group')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Shift Selection -->
                <div>
                    <label for="create_shift_id" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                        {{ __('Shift') }} <span class="text-red-500">*</span>
                    </label>
                    <select 
                        id="create_shift_id"
                        wire:model.live="shift_id"
                        class="form-select w-full text-sm border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 dark:text-neutral-300 focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600 rounded-md shadow-sm"
                        required
                    >
                        <option value="">{{ __('Select Shift') }}</option>
                        @foreach($shifts as $shift)
                            <option value="{{ $shift->id }}">{{ $shift->name }}</option>
                        @endforeach
                    </select>
                    @error('shift_id')
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <!-- Work Start Time -->
                    <div>
                        <label for="create_work_start_time" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                            {{ __('Work Start Time') }} <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="time" 
                            id="create_work_start_time"
                            wire:model.live="work_start_time"
                            class="form-input w-full text-sm border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 dark:text-neutral-300 focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600 rounded-md shadow-sm"
                            required
                        >
                        @error('work_start_time')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Work End Time -->
                    <div>
                        <label for="create_work_end_time" class="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                            {{ __('Work End Time') }} <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="time" 
                            id="create_work_end_time"
                            wire:model.live="work_end_time"
                            class="form-input w-full text-sm border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 dark:text-neutral-300 focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600 rounded-md shadow-sm"
                            required
                        >
                        @error('work_end_time')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <!-- Is Working Day -->
                <div class="flex items-center">
                    <input 
                        type="checkbox" 
                        id="create_is_working_day"
                        wire:model.live="is_working_day"
                        class="form-checkbox h-4 w-4 text-caldy-600 border-neutral-300 dark:border-neutral-700 rounded focus:ring-caldy-500 dark:focus:ring-caldy-600"
                    >
                    <label for="create_is_working_day" class="ml-2 block text-sm text-neutral-700 dark:text-neutral-300">
                        {{ __('Is Working Day') }}
                    </label>
                </div>

                <!-- Break Times Section -->
                <div class="border border-neutral-300 dark:border-neutral-700 rounded-lg p-4 space-y-4">
                    <div class="flex justify-between items-center">
                        <h3 class="text-sm font-semibold text-neutral-700 dark:text-neutral-300">
                            {{ __('Break Times') }}
                        </h3>
                    </div>

                    <!-- Existing Break Times -->
                    @if(count($break_times) > 0)
                        <div class="space-y-2">
                            @foreach($break_times as $index => $breakTime)
                                <div wire:key="create-break-{{ $index }}" class="flex items-center gap-2 p-2 bg-neutral-50 dark:bg-neutral-800 rounded">
                                    <div class="flex-1 text-sm text-neutral-700 dark:text-neutral-300">
                                        <i class="icon-clock text-neutral-400"></i>
                                        {{ is_array($breakTime) ? ($breakTime['start'] ?? '') . ' - ' . ($breakTime['end'] ?? '') : $breakTime }}
                                    </div>
                                    <button 
                                        type="button"
                                        wire:click="removeBreakTime({{ $index }})"
                                        class="text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                    >
                                        <i class="icon-trash text-sm"></i>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-neutral-500">{{ __('No break times added yet') }}</p>
                    @endif

                    <!-- Add New Break Time -->
                    <div class="pt-3 border-t border-neutral-200 dark:border-neutral-700">
                        <label class="block text-xs font-medium text-neutral-600 dark:text-neutral-400 mb-2">
                            {{ __('Add Break Time') }}
                        </label>
                        <div class="flex gap-2">
                            <input 
                                type="time" 
                                wire:model.live="break_start"
                                placeholder="Start"
                                class="form-input flex-1 text-sm border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 dark:text-neutral-300 focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600 rounded-md shadow-sm"
                            >
                            <span class="text-neutral-400 self-center">-</span>
                            <input 
                                type="time" 
                                wire:model.live="break_end"
                                placeholder="End"
                                class="form-input flex-1 text-sm border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 dark:text-neutral-300 focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600 rounded-md shadow-sm"
                            >
                            <button 
                                type="button"
                                wire:click="addBreakTime"
                                class="px-3 py-2 bg-caldy-500 hover:bg-caldy-600 text-white text-sm rounded-md transition-colors"
                                :disabled="!$wire.break_start || !$wire.break_end"
                            >
                                <i class="icon-plus"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="mt-6 flex justify-end gap-3">
                <x-secondary-button type="button" wire:click="closeModal">
                    {{ __('Cancel') }}
                </x-secondary-button>
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ __('Create Working Hours') }}</span>
                    <span wire:loading wire:target="save">
                        <i class="icon-loader animate-spin"></i> {{ __('Creating...') }}
                    </span>
                </x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
