<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;

use App\Models\InsStcMachine;
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
        $machines = InsStcMachine::where(function (Builder $query) use ($q) {
            $query->orWhere("code", "LIKE", "%" . $q . "%")->orWhere("name", "LIKE", "%" . $q . "%");
        })
            ->orderBy("line")
            ->paginate($this->perPage);

        return [
            "machines" => $machines,
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

<x-slot name="title">{{ __("Mesin") . " — " . __("Kendali chamber IP") }}</x-slot>
<x-slot name="header">
    <x-nav-insights-stc-sub />
</x-slot>
<div id="content" class="py-12 max-w-5xl mx-auto sm:px-3 text-neutral-800 dark:text-neutral-200">
    <div>
        <div class="flex flex-col sm:flex-row gap-y-6 justify-between px-6">
            <h1 class="text-2xl text-neutral-900 dark:text-neutral-100">{{ __("Mesin") }}</h1>
            <div x-data="{ open: false }" class="flex justify-end gap-x-2">
                @can("superuser")
                    <x-secondary-button type="button" x-on:click.prevent="$dispatch('open-modal', 'machine-create')"><i class="icon-plus"></i></x-secondary-button>
                @endcan

                <x-secondary-button type="button" x-on:click="open = true; setTimeout(() => $refs.search.focus(), 100)" x-show="!open">
                    <i class="icon-search"></i>
                </x-secondary-button>
                <div class="w-40" x-show="open" x-cloak>
                    <x-text-input-search wire:model.live="q" id="inv-q" x-ref="search" placeholder="{{ __('CARI') }}"></x-text-input-search>
                </div>
            </div>
        </div>
        <div wire:key="machine-create">
            <x-modal name="machine-create" maxWidth="xl">
                <livewire:insights.stc.manage.machine-create />
            </x-modal>
        </div>
        <div wire:key="machine-edit">
            <x-modal name="machine-edit" maxWidth="xl">
                <livewire:insights.stc.manage.machine-edit />
            </x-modal>
        </div>
        <div class="overflow-auto w-full my-8">
            <div class="p-0 sm:p-1">
                <div class="bg-white dark:bg-neutral-800 shadow table sm:rounded-lg">
                    <table wire:key="machines-table" class="table">
                        <tr>
                            <th>{{ __("Kode") }}</th>
                            <th>{{ __("Nama") }}</th>
                            <th>{{ __("Line") }}</th>
                            <th>{{ __("Alamat IP") }}</th>
                            <th colspan="2">{{ __("Penyetelan AT") }}</th>
                            <th>{{ __("Durasi Standar") }}</th>
                        </tr>
                        @foreach ($machines as $machine)
                            <tr
                                wire:key="machine-tr-{{ $machine->id . $loop->index }}"
                                tabindex="0"
                                x-on:click="
                                    $dispatch('open-modal', 'machine-edit')
                                    $dispatch('machine-edit', { id: {{ $machine->id }} })
                                "
                            >
                                <td>
                                    {{ $machine->code }}
                                </td>
                                <td>
                                    {{ $machine->name }}
                                </td>
                                <td>
                                    {{ $machine->line }}
                                </td>
                                <td>
                                    {{ $machine->ip_address }}
                                </td>
                                <td>
                                    <x-pill color="{{ $machine->is_at_adjusted ? 'green' : 'red' }}">
                                        {{ $machine->is_at_adjusted ? __("Aktif") : __("Nonaktif") }}
                                    </x-pill>
                                </td>
                                <td>
                                    @if ($machine->is_at_adjusted && $machine->at_adjust_strength)
                                        <div class="text-xs">
                                            <div>{{ implode(",", $machine->at_adjust_strength["upper"] ?? []) }}</div>
                                            <div>{{ implode(",", $machine->at_adjust_strength["lower"] ?? []) }}</div>
                                        </div>
                                    @else
                                        <span class="text-neutral-400">-</span>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $durationStd = is_array($machine->std_duration) ? $machine->std_duration : json_decode($machine->std_duration, true);
                                        
                                        if (isset($durationStd[0])) {
                                            $minSeconds = $durationStd[0];
                                            $minFormatted = $minSeconds >= 60 
                                                ? round($minSeconds / 60) . 'm' 
                                                : $minSeconds . 's';
                                        } else {
                                            $minFormatted = '-';
                                        }
                                        
                                        if (isset($durationStd[1])) {
                                            $maxSeconds = $durationStd[1];
                                            $maxFormatted = $maxSeconds >= 60 
                                                ? round($maxSeconds / 60) . 'm' 
                                                : $maxSeconds . 's';
                                        } else {
                                            $maxFormatted = '-';
                                        }
                                    @endphp
                                    @if ($durationStd && is_array($durationStd))
                                        <div class="text-xs">
                                            <div>{{ $minFormatted }} - {{ $maxFormatted }}</div>
                                        </div>
                                    @else
                                        <span class="text-neutral-400">-</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                    <div wire:key="machines-none">
                        @if (! $machines->count())
                            <div class="text-center py-12">
                                {{ __("Tak ada mesin ditemukan") }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        <div wire:key="observer" class="flex items-center relative h-16">
            @if (! $machines->isEmpty())
                @if ($machines->hasMorePages())
                    <div
                        wire:key="more"
                        x-data="{
                        observe() {
                            const observer = new IntersectionObserver((machines) => {
                                machines.forEach(machine => {
                                    if (machine.isIntersecting) {
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
