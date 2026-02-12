<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\Project;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

new #[Layout("layouts.app")] class extends Component {
    use WithPagination;

    public int $perPage = 20;
    public Project $project;

    #[Url]
    public string $q = "";

    protected $listeners = ['project-created' => '$refresh'];
    
    public function with(): array
    {
        $projects = Project::query();
        if ($this->q) {
            $projects->where("name", "like", "%" . $this->q . "%")
                ->orWhere("project_group", "like", "%" . $this->q . "%")
                ->orWhere("ip", "like", "%" . $this->q . "%");
        }
        $projects = $projects->paginate($this->perPage);
        return [
            "projects" => $projects,
        ];
    }
    public function updating($property)
    {
        if ($property == "q") {
            $this->reset("perPage");
        }
    }

    public function loadMore()
    {
        $this->perPage += 20;
    }

    public function openModal($modal, $data = [])
    {
        $this->dispatch('open-modal', $modal, $data);
    }

    public function projectEditLoad($id)
    {
        $this->project = Project::find($id);
    }
};

?>

<x-slot name="title">{{ __("Project Settings") }}</x-slot>
<div class="p-6 max-w-7xl mx-auto text-neutral-800 dark:text-neutral-200">
    <div class="text-neutral-800 dark:text-neutral-200">
        <h1 class="text-2xl font-bold text-neutral-800 dark:text-white mb-1">Project Settings</h1>
        <p class="text-sm text-neutral-500">Manage your project settings here</p>
    </div>

    <div class="mt-6">
        <div class="flex items-center gap-2">
            <div class="flex items-center gap-2">
                <input type="text" class="form-input w-full h-full text-sm border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 dark:text-neutral-300 focus:border-caldy-500 dark:focus:border-caldy-600 focus:ring-caldy-500 dark:focus:ring-caldy-600 rounded-md shadow-sm" placeholder="Search projects" wire:model.live.debounce.500ms="q">
            </div>
            @auth
            <div class="flex items-center gap-2">
                <x-secondary-button 
                    type="button" 
                    class="text-xs"
                    x-on:click="$dispatch('open-modal', 'project-create')"
                >
                    <i class="icon-plus"></i>
                    {{ __('Add Project') }}
                </x-secondary-button>
            </div>

            <!-- add settings working hours -->
            <a wire:navigate href="{{ route('uptime.projects.workhours') }}">
                <x-secondary-button type="button" class="text-xs">
                    <i class="icon-clock"></i>
                    {{ __('Settings Working Hours') }}
                </x-secondary-button>
            </a>
            @endauth
        </div>
    </div>

    <!-- List of Projects -->
    @if($projects->count() > 0)
    <div class="mt-6 p-6 bg-white dark:bg-neutral-800 rounded-xl border border-neutral-200 dark:border-neutral-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table-auto table table-sm text-sm table-truncate text-neutral-600 dark:text-neutral-400 w-full">
                <thead>
                    <tr>
                        <th class="text-left">Name</th>
                        <th class="text-left">Project Group</th>
                        <th class="text-left">IP Address</th>
                        <th class="text-left">Timeout</th>
                        <th class="text-left">Location</th>
                        <th class="text-left">Is Active</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($projects as $project)
                        <tr wire:key="project-{{ $project->id }}"
                        tabindex="0"
                        x-on:click="
                            $dispatch('open-modal', 'project-edit');
                            $dispatch('project-edit-load', { id: '{{ $project->id }}' });
                        "
                        class="hover:bg-neutral-50 dark:hover:bg-neutral-700 transition-colors cursor-pointer border-b border-neutral-100 dark:border-neutral-700/50"
                        >
                            <td>{{ $project->name }}</td>
                            <td>{{ $project->project_group }}</td>
                            <td>{{ $project->ip }}</td>
                            <td>{{ $project->timeout }}</td>
                            <td>{{ $project->location }}</td>
                            <td>{{ $project->is_active ? 'Yes' : 'No' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Infinite Scroll Trigger -->
        @if($projects->hasMorePages())
        <div 
            x-data="{
                observe() {
                    const observer = new IntersectionObserver((entries) => {
                        entries.forEach(entry => {
                            if (entry.isIntersecting) {
                                @this.call('loadMore');
                            }
                        });
                    }, { threshold: 1.0 });
                    observer.observe(this.$el);
                }
            }"
            x-init="observe"
            class="flex items-center justify-center py-4"
        >
            <div wire:loading wire:target="loadMore" class="text-neutral-500 dark:text-neutral-400 text-sm">
                <i class="icon-spinner animate-spin"></i> Loading more projects...
            </div>
        </div>
        @endif
    </div>
    @else
        <div class="mt-6 p-6 bg-white dark:bg-neutral-800 rounded-xl border border-neutral-200 dark:border-neutral-700 overflow-hidden">
            <div class="text-center text-neutral-500 dark:text-neutral-400">No projects found</div>
        </div>
    @endif

    <!-- Modals -->
    <livewire:uptime.projects.project-create />
    <livewire:uptime.projects.project-edit />
</div>