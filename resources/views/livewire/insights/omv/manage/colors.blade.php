<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

use App\Models\InsRubberColor;
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
        $colors = InsRubberColor::where(function (Builder $query) use ($q) {
            $query->orWhere("name", "LIKE", "%" . $q . "%")->orWhere("description", "LIKE", "%" . $q . "%");
        })
            ->orderBy("name")
            ->paginate($this->perPage);

        return [
            "colors" => $colors,
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

<x-slot name="title">{{ __("Warna") . " — " . __("Pemantauan open mill") }}</x-slot>
<x-slot name="header">
    <x-nav-insights-omv-sub />
</x-slot>
<div id="content" class="py-12 max-w-5xl mx-auto sm:px-3 text-neutral-800 dark:text-neutral-200">
    <div>
        <div class="flex flex-col sm:flex-row gap-y-6 justify-between px-6">
            <h1 class="text-2xl text-neutral-900 dark:text-neutral-100">{{ __("Warna") }}</h1>
            <div x-data="{ open: false }" class="flex justify-end gap-x-2">
                @can("superuser")
                    <x-secondary-button type="button" x-on:click.prevent="$dispatch('open-modal', 'color-create')"><i class="icon-plus"></i></x-secondary-button>
                @endcan

                <x-secondary-button type="button" x-on:click="open = true; setTimeout(() => $refs.search.focus(), 100)" x-show="!open">
                    <i class="icon-search"></i>
                </x-secondary-button>
                <div class="w-40" x-show="open" x-cloak>
                    <x-text-input-search wire:model.live="q" id="color-q" x-ref="search" placeholder="{{ __('CARI') }}"></x-text-input-search>
                </div>
            </div>
        </div>
        <div wire:key="color-create">
            <x-modal name="color-create" maxWidth="xl">
                <livewire:insights.omv.manage.color-create />
            </x-modal>
        </div>
        <div wire:key="color-edit">
            <x-modal name="color-edit" maxWidth="xl">
                <livewire:insights.omv.manage.color-edit />
            </x-modal>
        </div>
        <div class="overflow-auto w-full my-8">
            <div class="p-0 sm:p-1">
                <div class="bg-white dark:bg-neutral-800 shadow table sm:rounded-lg">
                    <table wire:key="colors-table" class="table">
                        <tr>
                            <th>{{ __("ID") }}</th>
                            <th>{{ __("Nama") }}</th>
                            <th>{{ __("Deskripsi") }}</th>
                        </tr>
                        @foreach ($colors as $color)
                            <tr
                                wire:key="color-tr-{{ $color->id . $loop->index }}"
                                tabindex="0"
                                x-on:click="
                                    $dispatch('open-modal', 'color-edit')
                                    $dispatch('color-edit', { id: {{ $color->id }} })
                                "
                            >
                                <td>
                                    {{ $color->id }}
                                </td>
                                <td>
                                    {{ $color->name }}
                                </td>
                                <td>
                                    {{ $color->description ?? '-' }}
                                </td>
                            </tr>
                        @endforeach
                    </table>
                    <div wire:key="colors-none">
                        @if (! $colors->count())
                            <div class="text-center py-12">
                                {{ __("Tak ada warna ditemukan") }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        <div wire:key="observer" class="flex items-center relative h-16">
            @if (! $colors->isEmpty())
                @if ($colors->hasMorePages())
                    <div
                        wire:key="more"
                        x-data="{
                        observe() {
                            const observer = new IntersectionObserver((colors) => {
                                colors.forEach(color => {
                                    if (color.isIntersecting) {
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
