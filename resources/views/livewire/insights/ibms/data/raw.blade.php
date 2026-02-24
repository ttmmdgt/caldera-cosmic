<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use App\Models\InsIbmsCount;
use App\Models\InsIbmsDevice;
use App\Traits\HasDateRangeFilter;
use Carbon\Carbon;

new class extends Component {
    use WithPagination, HasDateRangeFilter;

    #[Url]
    public $view = "dashboard";

    #[Url]
    public $shift = "";

    #[Url]
    public string $start_at = "";

    #[Url]
    public string $end_at = "";

    #[Url]
    public int $machine_id = 0;

    public int $perPage = 10;

    public array $view_titles = [];
    public array $view_icons = [];

    public function mount()
    {
        $this->view_titles = [
            "dashboard" => __("Dashboard"),
            "raw" => __("Raw Data"),
        ];

        $this->view_icons = [
            "dashboard" => "icon-layout-dashboard",
            "raw" => "icon-database",
        ];

        if (! $this->start_at || ! $this->end_at) {
            $this->setToday();
        }
    }

    public function getViewTitle(): string
    {
        return $this->view_titles[$this->view] ?? "";
    }

    public function getViewIcon(): string
    {
        return $this->view_icons[$this->view] ?? "";
    }

    #[On('update-menu')]
    public function updateMenu($view){
        // Condition Menu
        if ($view === "raw" || $view === "history") {
            $this->view_titles = [
                "dashboard" => __("Dashboard"),
                "raw" => __("Raw Data"),
            ];

            $this->view_icons = [
                "dashboard" => "icon-layout-dashboard",
                "raw" => "icon-database",
            ];
        }
    }

    public function getDevices()
    {
        return InsIbmsDevice::orderBy("name")
            ->get();
    }

    public function download($type)
    {
        if ($type === "counts") {
            $filename = "ibms_counts_" . Carbon::now()->format("Ymd_His") . ".csv";
            $data     = InsIbmsCount::whereBetween("created_at", [Carbon::parse($this->start_at)->startOfDay(), Carbon::parse($this->end_at)->endOfDay()])
                ->get();

            $csvData = "ID,Shift,Duration,Data,Created At\n";
            foreach ($data as $item) {
                $csvData .= "{$item->id},{$item->shift},{$item->duration},\"{$item->data}\",{$item->created_at}\n";
            }

            return response()->streamDownload(function() use ($csvData) {
                echo $csvData;
            }, $filename);
        }
    }

    public function getCountsQuery()
    {
        $query = InsIbmsCount::query();

        if ($this->shift) {
            $query->where("shift", $this->shift);
        }

        // where data->name = machine_id
        if ($this->machine_id) {
            $query->where("data->name", (string) $this->machine_id);
        }
        
        return $query->whereBetween("created_at", [Carbon::parse($this->start_at)->startOfDay(), Carbon::parse($this->end_at)->endOfDay()])
            ->orderBy("created_at", "desc");
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
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Shift") }}</label>
                    <x-select wire:model.live="shift" class="w-full">
                        <option value="">{{ __("Semua") }}</option>
                        <option value="A">A</option>
                        <option value="B">B</option>
                        <option value="C">C</option>
                    </x-select>
                </div>

                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Machine") }}</label>
                    <x-select wire:model.live="machine_id" class="w-full">
                        <option value="0">{{ __("Semua") }}</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                    </x-select>
                </div>
            </div>
            <div class="border-l border-neutral-300 dark:border-neutral-700 mx-2"></div>
            <div class="grow flex justify-between gap-x-2 items-center">
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
                        <th style="text-align: center;">{{ __("Shift") }}</th>
                        <th style="text-align: center;">{{ __("Machine") }}</th>
                        <th style="text-align: center;">{{ __("Duration") }}</th>
                        <th style="text-align: center;">{{ __("Status") }}</th>
                        <th style="text-align: center;">{{ __("Timestamp") }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($counts as $count)
                        @php
                            $data = $count->data ?? [];
                            $status = $data['status'] ?? 'unknown';
                            $machineName = $data['name'] ?? 'N/A';
                            $statusColors = [
                                'normal' => 'bg-green-100 text-green-800',
                                'warning' => 'bg-yellow-100 text-yellow-800',
                                'error' => 'bg-red-100 text-red-800',
                                'unknown' => 'bg-gray-100 text-gray-800',
                            ];
                            $colorClass = $statusColors[$status] ?? $statusColors['unknown'];
                        @endphp
                        <tr wire:key="count-tr-{{ $count->id }}" class="hover:bg-neutral-50 dark:hover:bg-neutral-700">
                            <td class="text-center">{{ $count->shift }}</td>
                            <td class="text-center">{{ $machineName }}</td>
                            <td class="text-center">{{ $count->duration }}</td>
                            <td class="text-center">
                                <span class="px-2 py-1 rounded-full text-xs {{ $colorClass }}">
                                    {{ strtoupper($status) }}
                                </span>
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
