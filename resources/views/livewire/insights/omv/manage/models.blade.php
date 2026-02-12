<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

use App\Models\InsRubberModel;
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
        $models = InsRubberModel::where(function (Builder $query) use ($q) {
            $query->orWhere("name", "LIKE", "%" . $q . "%")->orWhere("description", "LIKE", "%" . $q . "%");
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

<x-slot name="title">{{ __("Model") . " — " . __("Pemantauan open mill") }}</x-slot>
<x-slot name="header">
    <x-nav-insights-omv-sub />
</x-slot>
<div id="content" class="py-12 max-w-5xl mx-auto sm:px-3 text-neutral-800 dark:text-neutral-200">
    <div>
        <div class="flex flex-col sm:flex-row gap-y-6 justify-between px-6">
            <h1 class="text-2xl text-neutral-900 dark:text-neutral-100">{{ __("Model") }}</h1>
            <div x-data="{ open: false }" class="flex justify-end gap-x-2">
                @can("superuser")
                    <x-secondary-button type="button" x-on:click.prevent="$dispatch('open-modal', 'model-create')"><i class="icon-plus"></i></x-secondary-button>
                @endcan

                <x-secondary-button type="button" x-on:click="open = true; setTimeout(() => $refs.search.focus(), 100)" x-show="!open">
                    <i class="icon-search"></i>
                </x-secondary-button>
                <div class="w-40" x-show="open" x-cloak>
                    <x-text-input-search wire:model.live="q" id="model-q" x-ref="search" placeholder="{{ __('CARI') }}"></x-text-input-search>
                </div>
            </div>
        </div>
        <div wire:key="model-create">
            <x-modal name="model-create" maxWidth="xl">
                <livewire:insights.omv.manage.model-create />
            </x-modal>
        </div>
        <div wire:key="model-edit">
            <x-modal name="model-edit" maxWidth="xl">
                <livewire:insights.omv.manage.model-edit />
            </x-modal>
        </div>
        <div class="overflow-auto w-full my-8">
            <div class="p-0 sm:p-1">
                <div class="bg-white dark:bg-neutral-800 shadow table sm:rounded-lg">
                    <table wire:key="models-table" class="table">
                        <tr>
                            <th>{{ __("ID") }}</th>
                            <th>{{ __("Nama") }}</th>
                            <th>{{ __("Deskripsi") }}</th>
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
                                    {{ $model->id }}
                                </td>
                                <td>
                                    {{ $model->name }}
                                </td>
                                <td>
                                    {{ $model->description ?? '-' }}
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
