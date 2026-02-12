<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\ProjectWorkingHour;
use App\Models\Project;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Layout("layouts.app")] class extends Component {
    use WithPagination;
    
    #[Url]
    public $q = '';

    public function deleteWorkingHour($id)
    {
        $workingHour = ProjectWorkingHour::find($id);
        
        if ($workingHour) {
            $workingHour->delete();
            session()->flash('message', 'Working hour deleted successfully!');
        }
    }

    #[\Livewire\Attributes\On('workhour-updated')]
    public function refreshComponent()
    {
        // This will refresh the component when a working hour is updated
    }

    public function with()
    {
        $projects = Project::select('id', 'project_group', 'name', 'ip', 'is_active')
            ->with(['workingHours.shift'])
            ->when($this->q, function ($query) {
                $query->where('name', 'like', '%' . $this->q . '%');
            })
            ->orderBy('name')
            ->paginate(15);
        $projects = $projects->groupBy('project_group');
        return [
            'projects' => $projects,
        ];
    }
};

?>

<x-slot name="title">{{ __("Settings Working Hours") }}</x-slot>
<div class="p-6 max-w-7xl mx-auto text-neutral-800 dark:text-neutral-200">
    <!-- Flash Messages -->
    @if (session()->has('message'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)" class="mb-4 p-4 bg-green-100 dark:bg-green-900 border border-green-400 dark:border-green-700 text-green-700 dark:text-green-300 rounded-lg">
            {{ session('message') }}
        </div>
    @endif
    
    @if (session()->has('error'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)" class="mb-4 p-4 bg-red-100 dark:bg-red-900 border border-red-400 dark:border-red-700 text-red-700 dark:text-red-300 rounded-lg">
            {{ session('error') }}
        </div>
    @endif

    <div class="text-neutral-800 dark:text-neutral-200">
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-white mb-1">Settings Working Hours</h1>
        <p class="text-sm text-neutral-500">Manage your machine working hours here</p>
    </div>

    <div class="mt-6">
        <div class="flex items-center gap-2 w-[15%]">
            <input type="text" class="form-input w-full h-full text-sm border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 dark:text-neutral-300 focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600 rounded-md shadow-sm" placeholder="Search projects" wire:model.live.debounce.500ms="q">
        </div>
    </div>

    <!-- List of Projects with Working Hours -->
    @if($projects->count() > 0 && $projects->isNotEmpty())
    <div class="mt-6 space-y-4">
        @foreach($projects as $projectGroup => $projects)
            <div wire:key="project-{{ $projectGroup }}" class="p-6 bg-white dark:bg-neutral-800 rounded-xl border border-neutral-200 dark:border-neutral-700 overflow-hidden">
                <!-- Project Header -->
                <div class="mb-4 pb-4 border-b border-neutral-200 dark:border-neutral-700">
                    <div>
                        <div class="flex items-center gap-2">
                            <div class="flex-1">
                                <h3 class="text-lg font-semibold text-neutral-800 dark:text-white">{{ $projectGroup }}</h3>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-secondary-button 
                                    type="button" 
                                    class="text-xs"
                                    x-on:click="
                                        $dispatch('open-modal', 'workhour-create');
                                        $dispatch('workhour-create-load', { projectGroup: '{{ $projectGroup }}' });
                                    "
                                >
                                    <i class="icon-plus"></i>
                                    {{ __('Add Working Hours') }}
                                </x-secondary-button>
                            </div>
                        </div>
                        <div class="flex items-center gap-4 mt-1 text-xs text-neutral-500">
                            <span>Group: {{ $projectGroup ?? 'N/A' }}</span>
                            <span>IP: {{ $projects->first()->ip ?? 'N/A' }}</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded {{ $projects->first()->is_active ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300' }}">
                                {{ $projects->first()->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Working Hours List -->
                @if($projects->first()->workingHours->count() > 0)
                <div class="overflow-x-auto">
                    <table class="table-auto table table-sm text-sm text-neutral-600 dark:text-neutral-400 w-full">
                        <thead>
                            <tr class="border-b border-neutral-200 dark:border-neutral-700">
                                <th class="text-left py-2">Shift</th>
                                <th class="text-left py-2">Work Start</th>
                                <th class="text-left py-2">Work End</th>
                                <th class="text-left py-2">Working Day</th>
                                <th class="text-left py-2">Break Times</th>
                                <th class="text-center py-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($projects->first()->workingHours as $workingHour)
                                <tr wire:key="workhour-{{ $workingHour->id }}" class="border-b border-neutral-100 dark:border-neutral-700/50">
                                    <td class="py-2">{{ $workingHour->shift->name ?? 'N/A' }}</td>
                                    <td class="py-2">{{ $workingHour->work_start_time ?? 'N/A' }}</td>
                                    <td class="py-2">{{ $workingHour->work_end_time ?? 'N/A' }}</td>
                                    <td class="py-2">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs {{ $workingHour->is_working_day ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300' : 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300' }}">
                                            {{ $workingHour->is_working_day ? 'Yes' : 'No' }}
                                        </span>
                                    </td>
                                    <td class="py-2">
                                        @if($workingHour->break_times && count($workingHour->break_times) > 0)
                                            <div class="flex flex-wrap gap-1">
                                                @foreach($workingHour->break_times as $breakTime)
                                                    <span class="text-xs px-2 py-0.5 bg-neutral-100 dark:bg-neutral-700 rounded">
                                                        {{ is_array($breakTime) ? implode(' - ', $breakTime) : $breakTime }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="text-neutral-400">No breaks</span>
                                        @endif
                                    </td>
                                    <td class="py-2">
                                        <div class="flex items-center gap-2">
                                            <button 
                                                type="button"
                                                x-on:click="
                                                    $dispatch('open-modal', 'workhour-edit');
                                                    $dispatch('workhour-edit-load', { id: {{ $workingHour->id }} });
                                                "
                                                class="text-caldy-600 hover:text-caldy-700 dark:text-caldy-400 dark:hover:text-caldy-300 text-sm bg-neutral-100 dark:bg-neutral-700 rounded-md p-2"
                                                title="Edit"
                                            >
                                                <i class="icon-pencil"></i>
                                            </button>
                                            <button 
                                                type="button"
                                                wire:click="deleteWorkingHour({{ $workingHour->id }})"
                                                wire:confirm="Are you sure you want to delete this working hour record for {{ $workingHour->shift->name ?? 'this shift' }}?"
                                                class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 text-sm bg-neutral-100 dark:bg-neutral-700 rounded-md p-2"
                                                title="Delete"
                                            >
                                                <i class="icon-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                <div class="text-center py-8 text-neutral-500">
                    <i class="icon-clock text-4xl mb-2"></i>
                    <p>No working hours configured for this project</p>
                </div>
                @endif
            </div>
        @endforeach
    </div>
    @else
    <div class="mt-6 p-12 bg-white dark:bg-neutral-800 rounded-xl border border-neutral-200 dark:border-neutral-700 text-center">
        <div class="text-neutral-400">
            <i class="icon-inbox text-5xl mb-3"></i>
            <p class="text-lg">No projects found</p>
            @if($q)
            <p class="text-sm mt-2">Try adjusting your search terms</p>
            @endif
        </div>
    </div>
    @endif

    <!-- Create Working Hour Modal -->
    <livewire:uptime.projects.workhours-create />
    
    <!-- Edit Working Hour Modal -->
    <livewire:uptime.projects.workhours-edit />
</div>
