<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use App\Models\InsBpmCount;
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
    public string $line = "";

    #[Url]
    public string $machine = "";

    #[Url]
    public string $condition = "all";

    public int $perPage = 20;
    public $view = "raw";

    public function mount()
    {
        if (! $this->start_at || ! $this->end_at) {
            $this->setThisWeek();
        }

        $this->dispatch("update-menu", $this->view);
    }

    private function getCountsQuery()
    {
        $start = Carbon::parse($this->start_at);
        $end = Carbon::parse($this->end_at)->endOfDay();

        $query = InsBpmCount::whereBetween("created_at", [$start, $end])->where("cumulative", ">", 0);

        if ($this->line) {
            $query->where("line", "like", "%" . strtoupper(trim($this->line)) . "%");
        }

        if ($this->machine) {
            $query->where("machine", "like", "%" . strtoupper(trim($this->machine)) . "%");
        }

        if ($this->condition !== "all") {
            $query->where("condition", $this->condition);
        }

        return $query->orderBy("created_at", "DESC");
    }

    public function loadMore()
    {
        $this->perPage += 10;
    }

    private function getUniqueLines()
    {
        return InsBpmCount::distinct()->pluck('line')->sort()->toArray();
    }

    private function getUniqueMachines()
    {
        return InsBpmCount::distinct()->pluck('machine')->sort()->toArray();
    }

    #[On("updated")]
    public function with(): array
    {
        $counts = $this->getCountsQuery()->paginate($this->perPage);
        return [
            "counts" => $counts,
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
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Line") }}</label>
                    <x-select wire:model.live="line" class="w-full">
                        <option value="">{{ __("Semua") }}</option>
                        @foreach($this->getUniqueLines() as $lineOption)
                            <option value="{{$lineOption}}">{{$lineOption}}</option>
                        @endforeach
                    </x-select>
                </div>
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Machine") }}</label>
                    <x-select wire:model.live="machine" class="w-full">
                        <option value="">{{ __("Semua") }}</option>
                        @foreach($this->getUniqueMachines() as $machineOption)
                            <option value="{{$machineOption}}">{{$machineOption}}</option>
                        @endforeach
                    </x-select>
                </div>
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Condition") }}</label>
                    <x-select wire:model.live="condition" class="w-full">
                        <option value="">{{ __("Semua") }}</option>
                        <option value="hot">{{ __("Hot") }}</option>
                        <option value="cold">{{ __("Cold") }}</option>
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

    @if (! $counts->count())
        @if (! $start_at || ! $end_at)
            <div wire:key="no-range" class="py-20">
                <div class="text-center text-neutral-300 dark:text-neutral-700 text-5xl mb-3">
                    <i class="icon-calendar relative"><i class="icon-circle-help absolute bottom-0 -right-1 text-lg text-neutral-500 dark:text-neutral-400"></i></i>
                </div>
                <div class="text-center text-neutral-400 dark:text-neutral-600">{{ __("Pilih rentang tanggal") }}</div>
            </div>
        @else
            <div wire:key="no-match" class="py-20">
                <div class="text-center text-neutral-300 dark:text-neutral-700 text-5xl mb-3">
                    <i class="icon-ghost"></i>
                </div>
                <div class="text-center text-neutral-500 dark:text-neutral-600">{{ __("Tidak ada yang cocok") }}</div>
            </div>
        @endif
    @else
        <div wire:key="raw-counts" class="overflow-auto p-0 sm:p-1">
            <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg table">
                <table class="table table-sm text-sm table-truncate text-neutral-600 dark:text-neutral-400">
                    <tr class="uppercase text-xs">
                        <th>{{ __("Line") }}</th>
                        <th>{{ __("Machine") }}</th>
                        <th>{{ __("Condition") }}</th>
                        <th>{{ __("Cumulative") }}</th>
                        <th>{{ __("Timestamp") }}</th>
                    </tr>
                    @foreach ($counts as $count)
                        <tr wire:key="count-tr-{{ $count->id }}" class="hover:bg-neutral-50 dark:hover:bg-neutral-700">
                            <td>{{ $count->line }}</td>
                            <td>{{ $count->machine }}</td>
                            <td>
                                @if($count->condition === 'hot')
                                    <span class="text-red-600 dark:text-red-400">
                                        <i class="icon-flame me-1"></i>{{ __("Hot") }}
                                    </span>
                                @else
                                    <span class="text-blue-600 dark:text-blue-400">
                                        <i class="icon-snowflake me-1"></i>{{ __("Cold") }}
                                    </span>
                                @endif
                            </td>
                            <td class="font-mono">{{ number_format($count->cumulative) }}</td>
                            <td class="font-mono">{{ $count->created_at }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
        <div class="flex items-center relative h-16">
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
    @endif
</div>

