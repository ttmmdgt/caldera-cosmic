<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

use App\Models\InsStcModels;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Illuminate\Database\Eloquent\Builder;

new #[Layout("layouts.app")] class extends Component {
    use WithPagination;

    #[Url]
    public $q = "";

    public $perPage = 20;

    #[On("updated")]
    public function with(): array
    {
        $q = trim($this->q);
        $models = InsStcModels::where(function (Builder $query) use ($q) {
            $query->orWhere("name", "LIKE", "%" . $q . "%");
        })
            ->orderBy("name")
            ->paginate($this->perPage);

        return [
            "models" => $models,
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
};
?>

<x-slot name="title">{{ __("Models") . " — " . __("Kendali chamber IP") }}</x-slot>
<x-slot name="header">
    <x-nav-insights-stc-sub />
</x-slot>
<div id="content" class="py-12 max-w-5xl mx-auto sm:px-3 text-neutral-800 dark:text-neutral-200">
    <div>
        <div class="flex flex-col sm:flex-row gap-y-6 justify-between px-6">
            <h1 class="text-2xl text-neutral-900 dark:text-neutral-100">{{ __("Models") }}</h1>
            <div x-data="{ open: false }" class="flex justify-end gap-x-2">
                @can("superuser")
                    <x-secondary-button type="button" x-on:click.prevent="$dispatch('open-modal', 'model-create')"><i class="icon-plus"></i></x-secondary-button>
                @endcan

                <x-secondary-button type="button" x-on:click="open = true; setTimeout(() => $refs.search.focus(), 100)" x-show="!open">
                    <i class="icon-search"></i>
                </x-secondary-button>
                <div class="w-40" x-show="open" x-cloak>
                    <x-text-input-search wire:model.live="q" id="inv-q" x-ref="search" placeholder="{{ __('CARI') }}"></x-text-input-search>
                </div>
            </div>
        </div>
        <div wire:key="model-create">
            <x-modal name="model-create" maxWidth="xl">
                <livewire:insights.stc.manage.model-create />
            </x-modal>
        </div>
        <div wire:key="model-edit">
            <x-modal name="model-edit" maxWidth="xl">
                <livewire:insights.stc.manage.model-edit />
            </x-modal>
        </div>
        <div class="overflow-auto w-full my-8">
            <div class="p-0 sm:p-1">
                <div class="bg-white dark:bg-neutral-800 shadow table sm:rounded-lg">
                    <table wire:key="models-table" class="table">
                        <tr>
                            <th>{{ __("Nama Model") }}</th>
                            <th>{{ __("Standard Temperature") }}</th>
                            <th>{{ __("Standard Duration") }}</th>
                            <th>{{ __("Status") }}</th>
                        </tr>
                        @foreach ($models as $model)
                            <tr
                                wire:key="model-tr-{{ $model->id . $loop->index }}"
                                tabindex="0"
                                x-on:click="
                                    $dispatch('open-modal', 'model-edit')
                                    $dispatch('model-edit', { id: {{ $model->id }} })
                                "
                            >
                                <td>
                                    {{ $model->name }}
                                </td>
                                <td>
                                    @if (is_array($model->std_temperature) && !empty(array_filter($model->std_temperature, fn($zone) => is_array($zone) && !empty(array_filter($zone)))))
                                        <div class="text-xs">
                                            @foreach ($model->std_temperature as $index => $zone)
                                                @if (is_array($zone) && isset($zone[0]) && isset($zone[1]))
                                                    <div>Z{{ $index + 1 }}: {{ $zone[0] }}-{{ $zone[1] }}°C</div>
                                                @endif
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-neutral-400">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if (is_array($model->std_duration) && !empty(array_filter($model->std_duration)))
                                        <div class="text-xs">
                                            @php
                                                $formattedDurations = array_map(function($duration) {
                                                    return $duration >= 60 
                                                        ? round($duration / 60) . 'm' 
                                                        : $duration . 's';
                                                }, $model->std_duration);
                                            @endphp
                                            {{ implode(' - ', $formattedDurations) }}
                                        </div>
                                    @else
                                        <span class="text-neutral-400">-</span>
                                    @endif
                                </td>
                                <td>
                                    <x-pill color="{{ $model->status === 'active' ? 'green' : 'red' }}">
                                        {{ $model->status === 'active' ? __("Aktif") : __("Nonaktif") }}
                                    </x-pill>
                                </td>
                            </tr>
                        @endforeach
                    </table>
                    <div wire:key="models-none">
                        @if (! $models->count())
                            <div class="text-center py-12">
                                {{ __("Tak ada model ditemukan") }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        <div wire:key="observer" class="flex items-center relative h-16">
            @if (! $models->isEmpty())
                @if ($models->hasMorePages())
                    <div
                        wire:key="more"
                        x-data="{
                        observe() {
                            const observer = new IntersectionObserver((models) => {
                                models.forEach(model => {
                                    if (model.isIntersecting) {
                                        @this.loadMore()
                                    }
                                })
                            })
                            observer.observe(this.$el)
                        }
                    }"
                        x-init="observe"
                    ></div>
                    <x-spinner class="sm" />
                @else
                    <div class="mx-auto">{{ __("Tidak ada lagi") }}</div>
                @endif
            @endif
        </div>
    </div>
</div>
