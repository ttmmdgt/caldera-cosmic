<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use App\Models\InsPhDosingCount;
use App\Models\InsPhDosingDevice;
use App\Traits\HasDateRangeFilter;
use Carbon\Carbon;

new class extends Component {
    use WithPagination;
    use HasDateRangeFilter;

    #[Url]
    public string $start_at = "";

    #[Url]
    public string $end_at = "";

    #[Url]
    public string $plant = "1";

    #[Url]
    public string $machine = "";

    #[Url]
    public string $condition = "all";

    public int $perPage = 10;
    public $view = "raw";

    public function mount()
    {
        if (! $this->start_at || ! $this->end_at) {
            $this->setThisWeek();
        }

        $this->dispatch("update-menu", $this->view);
    }

    public function getUniquePlants()
    {
        return InsPhDosingDevice::orderBy("plant")
            ->get()
            ->pluck("plant", "id")
            ->toArray();
    }

    public function getCountsQuery()
    {
        $start = Carbon::parse($this->start_at);
        $end = Carbon::parse($this->end_at)->endOfDay();

        $query = InsPhDosingCount::with('device')
            ->whereBetween("created_at", [$start, $end]);

        if ($this->plant) {
            $query->whereHas('device', function($q) {
                $q->where('id', $this->plant);
            });
        }

        return $query->orderBy("created_at", "DESC");
    }

    public function loadMore()
    {
        $this->perPage += 10;
    }

    public function with(): array
    {
        return [
            'counts' => $this->getCountsQuery()->paginate($this->perPage),
        ];
    }

}; ?>

<div>
    <div class="p-0 sm:p-1 mb-6">
        <div class="flex flex-col lg:flex-row gap-3 w-full bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-4">
            <div>
                <div class="flex mb-2 text-xs text-neutral-500">
                    <div class="flex">
                        <x-dropdown align="left" width="48">
                            <x-slot name="trigger">
                                <x-text-button class="uppercase ml-3">
                                    {{ __("Rentang") }}
                                    <i class="icon-chevron-down ms-1"></i>
                                </x-text-button>
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link href="#" wire:click.prevent="setToday">
                                    {{ __("Hari ini") }}
                                </x-dropdown-link>
                                <x-dropdown-link href="#" wire:click.prevent="setYesterday">
                                    {{ __("Kemarin") }}
                                </x-dropdown-link>
                                <hr class="border-neutral-300 dark:border-neutral-600" />
                                <x-dropdown-link href="#" wire:click.prevent="setThisWeek">
                                    {{ __("Minggu ini") }}
                                </x-dropdown-link>
                                <x-dropdown-link href="#" wire:click.prevent="setLastWeek">
                                    {{ __("Minggu lalu") }}
                                </x-dropdown-link>
                                <hr class="border-neutral-300 dark:border-neutral-600" />
                                <x-dropdown-link href="#" wire:click.prevent="setThisMonth">
                                    {{ __("Bulan ini") }}
                                </x-dropdown-link>
                                <x-dropdown-link href="#" wire:click.prevent="setLastMonth">
                                    {{ __("Bulan lalu") }}
                                </x-dropdown-link>
                            </x-slot>
                        </x-dropdown>
                    </div>
                </div>
                <div class="flex gap-3">
                    <x-text-input wire:model.live="start_at" type="date" class="w-40" />
                    <x-text-input wire:model.live="end_at" type="date" class="w-40" />
                </div>
            </div>
            <div class="border-l border-neutral-300 dark:border-neutral-700 mx-2"></div>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Plant") }}</label>
                    <x-select wire:model.live="plant" class="w-full">
                        <option value="">{{ __("Semua") }}</option>
                        @foreach($this->getUniquePlants() as $id => $plantOption)
                            <option value="{{$id}}">{{$plantOption}}</option>
                        @endforeach
                    </x-select>
                </div>
            </div>
            <div class="border-l border-neutral-300 dark:border-neutral-700 mx-2"></div>
            <div class="grow flex justify-between gap-x-2 items-center">
                <div>
                    <div class="px-3">
                        <div wire:loading.class="hidden">{{ $counts->total() . " " . __("entri") }}</div>
                        <div wire:loading.class.remove="hidden" class="hidden">{{ __("Memuat...") }}</div>
                    </div>
                </div>
                <div class="flex gap-x-2">
                    <x-dropdown align="right" width="48">
                        <x-slot name="trigger">
                            <x-text-button><i class="icon-ellipsis-vertical"></i></x-text-button>
                        </x-slot>
                        <x-slot name="content">
                            <x-dropdown-link href="#" wire:click.prevent="download('counts')">
                                <i class="icon-download me-2"></i>
                                {{ __("CSV Data") }}
                            </x-dropdown-link>
                        </x-slot>
                    </x-dropdown>
                </div>
            </div>
        </div>
    </div>
    <div wire:key="raw-counts" class="overflow-auto p-0 sm:p-1">
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg">
            <table class="table table-sm text-sm table-truncate text-neutral-600 dark:text-neutral-400">
                <thead>
                    <tr class="uppercase text-xs text-center">
                        <th style="text-align: center;">{{ __("Plant") }}</th>
                        <th style="text-align: center;">{{ __("PH Value") }}</th>
                        <th style="text-align: center;">{{ __("Status") }}</th>
                        <th style="text-align: center;">{{ __("Timestamp") }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($counts as $count)
                    @php
                        $phValue = $count->ph_value;
                    @endphp
                        <tr wire:key="count-tr-{{ $count->id }}" class="hover:bg-neutral-50 dark:hover:bg-neutral-700">
                            <td class="text-center">{{ $count->device->plant }}</td>
                            <td class="text-center">
                                <span class="text-green-600 dark:text-green-400">
                                    <i class="me-1"></i>{{ number_format($phValue['current_ph'], 2) }}
                                </span>
                            </td>
                            <!-- get status from current ph value 2-3 normal 3> high <2 low -->
                            <td class="text-center">
                                @if($phValue['current_ph'] >= 2 && $phValue['current_ph'] <= 3)
                                    <span class="text-green-600 dark:text-green-400">
                                        <i class="me-1"></i>{{ __("Normal") }}
                                    </span>
                                @elseif($phValue['current_ph'] > 3)
                                    <span class="text-red-600 dark:text-red-400">
                                        <i class="me-1"></i>{{ __("High") }}
                                    </span>
                                @else
                                    <span class="text-red-600 dark:text-red-400">
                                        <i class="me-1"></i>{{ __("Low") }}
                                    </span>
                                @endif
                            </td>
                            <td class="text-center">{{ $count->created_at->format("d-m-Y H:i:s") }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div wire:key="raw-counts-more" class="flex items-center relative h-16">
            @if (! $counts->isEmpty())
                @if ($counts->hasMorePages())
                    <div
                        wire:key="more"
                        x-data="{
                        observe() {
                            const observer = new IntersectionObserver((counts) => {
                                counts.forEach(count => {
                                    if (count.isIntersecting) {
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