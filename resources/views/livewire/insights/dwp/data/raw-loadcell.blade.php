<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use App\Models\InsDwpLoadcell;
use App\Traits\HasDateRangeFilter;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout("layouts.app")] class extends Component {
    use WithPagination;
    use HasDateRangeFilter;

    #[Url]
    public string $start_at = "";

    #[Url]
    public string $end_at = "";

    #[Url]
    public string $line = "";

    #[Url]
    public string $plant = "";

    #[Url]
    public string $mechine = "";

    public int $perPage = 20;
    public string $view = "raw-loadcell";

    public function mount()
    {
        if (! $this->start_at || ! $this->end_at) {
            $this->setThisWeek();
        }

        // update menu
        $this->dispatch("update-menu", $this->view);
    }

    private function getCountsQuery()
    {
        $start = Carbon::parse($this->start_at);
        $end = Carbon::parse($this->end_at)->endOfDay();

        $query = InsDwpLoadcell::whereBetween("created_at", [$start, $end]);

        if ($this->line) {
            $query->where("line", "like", "%" . strtoupper(trim($this->line)) . "%");
        }

        if ($this->plant) {
            $query->where("plant", "like", "%" . strtoupper(trim($this->plant)) . "%");
        }

        if ($this->mechine) {
            $query->where("machine_name", "like", "%" . strtoupper(trim($this->mechine)) . "%");
        }

        return $query->orderBy("created_at", "DESC");
    }

    public function with(): array
    {
        $counts = $this->getCountsQuery()->paginate($this->perPage);

        return [
            "counts" => $counts,
        ];
    }

    public function loadMore()
    {
        $this->perPage += 20;
    }

    public function download($type)
    {
        switch ($type) {
            case "counts":
                $this->js('toast("' . __("Unduhan dimulai...") . '", { type: "success" })');
                $filename = "dwp_loadcell_export_" . now()->format("Y-m-d_His") . ".csv";

                $headers = [
                    "Content-type" => "text/csv",
                    "Content-Disposition" => "attachment; filename=$filename",
                    "Pragma" => "no-cache",
                    "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
                    "Expires" => "0",
                ];

                $columns = [
                    __("Plant"),
                    __("Line"),
                    __("Machine"),
                    __("Position"),
                    __("Recorded Time"),
                    __("Latency"),
                    __("Operator"),
                    __("Timestamp"),
                ];

                $callback = function () use ($columns) {
                    $file = fopen("php://output", "w");
                    fputcsv($file, $columns);

                    $this->getCountsQuery()->chunk(1000, function ($counts) use ($file) {
                        foreach ($counts as $count) {
                            $latency = '';
                            if ($count->created_at && $count->recorded_at) {
                                $created = Carbon::parse($count->created_at);
                                $recorded = Carbon::parse($count->recorded_at);
                                $diff = $created->diff($recorded);
                                $latencyParts = [];
                                if ($diff->d > 0) $latencyParts[] = $diff->d . 'd';
                                if ($diff->h > 0) $latencyParts[] = $diff->h . 'h';
                                if ($diff->i > 0) $latencyParts[] = $diff->i . 'm';
                                if ($diff->s > 0) $latencyParts[] = $diff->s . 's';
                                $latency = implode(' ', $latencyParts);
                            }

                            fputcsv($file, [
                                strtoupper($count->plant ?? '-'),
                                strtoupper($count->line ?? '-'),
                                $count->machine_name ?? '-',
                                $count->position ?? '-',
                                $count->recorded_at ? Carbon::parse($count->recorded_at)->format('Y-m-d H:i:s') : '-',
                                $latency ?: '-',
                                $count->operator ?? '-',
                                $count->created_at ? $count->created_at->format('Y-m-d H:i:s') : '-',
                            ]);
                        }
                    });

                    fclose($file);
                };

                return new StreamedResponse($callback, 200, $headers);
        }
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
            <div class="grid grid-cols-2 lg:flex gap-3">
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Plant") }}</label>
                    <x-select wire:model.live="plant" class="w-full lg:w-32">
                            <option value=""></option>
                            <option value="Plant G">G</option>
                            <option value="Plant A">A</option>
                    </x-select>
                </div>
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Line") }}</label>
                    <x-select wire:model.live="line" class="w-full lg:w-32">
                            <option value=""></option>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>
                            <option value="5">5</option>
                    </x-select>
                </div>
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Machine") }}</label>
                    <x-select wire:model.live="mechine" class="w-full lg:w-32">
                            <option value=""></option>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>
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

    <div wire:key="modals">
        <x-modal name="detail-loadcell" maxWidth="4xl">
            <livewire:insights.dwp.data.detail.loadcell-result />
        </x-modal>
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
        <div key="raw-counts" class="overflow-x-auto overflow-y-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
            <div class="min-w-full bg-white dark:bg-neutral-800 shadow-sm">
                <table class="min-w-full text-sm text-neutral-600 dark:text-neutral-400">
                    <thead class="sticky top-0 z-10 bg-white dark:bg-neutral-800 border-b border-neutral-200 dark:border-neutral-700">
                        <tr class="uppercase text-xs text-left">
                            <th class="py-3 px-4 font-medium">Plant</th>
                            <th class="py-3 px-4 font-medium">Line</th>
                            <th class="py-3 px-4 font-medium">Machine</th>
                            <th class="py-3 px-4 font-medium">Position</th>
                            <th class="py-3 px-4 font-medium">Recorded Time</th>
                            <th class="py-3 px-4 font-medium">Latency</th>
                            <th class="py-3 px-4 font-medium">Operator</th>
                            <th class="py-3 px-4 font-medium">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        @foreach ($counts as $count)
                            <tr wire:key="count-tr-{{ $count->id }}"
                                tabindex="0"
                                x-on:click="
                                    $dispatch('open-modal', 'detail-loadcell');
                                    $dispatch('loadcell-detail', { id: '{{ $count->id }}' });
                                " class="hover:bg-neutral-50 dark:hover:bg-neutral-700/50">
                                <td class="py-3 px-4">{{ strtoupper($count->plant ?? '-') }}</td>
                                <td class="py-3 px-4">{{ strtoupper($count->line ?? '-') }}</td>
                                <td class="py-3 px-4">{{ $count->machine_name ?? '-' }}</td>
                                <td class="py-3 px-4">{{ $count->position ?? '-' }}</td>
                                <td class="py-3 px-4">
                                    <div class="text-xs">
                                        {{ $count->recorded_at ? Carbon::parse($count->recorded_at)->format('Y-m-d H:i:s') : '-' }}
                                    </div>
                                </td>
                                <!-- latency calculate created_at - recorded_at make full 5 h 2m 1s -->
                                <td class="py-3 px-4">
                                    <div class="text-xs">
                                        @if ($count->created_at && $count->recorded_at)
                                            @php
                                                $created = Carbon::parse($count->created_at);
                                                $recorded = Carbon::parse($count->recorded_at);
                                                $latency = $created->diff($recorded);
                                                $latencyString = '';
                                                if ($latency->d > 0) {
                                                    $latencyString .= $latency->d . 'd ';
                                                }
                                                if ($latency->h > 0) {
                                                    $latencyString .= $latency->h . 'h ';
                                                }
                                                if ($latency->i > 0) {
                                                    $latencyString .= $latency->i . 'm ';
                                                }
                                                if ($latency->s > 0) {
                                                    $latencyString .= $latency->s . 's';
                                                }
                                            @endphp
                                            {{ trim($latencyString) }}
                                        @else
                                            -
                                        @endif
                                    </div>
                                </td>
                                <td class="py-3 px-4">{{ $count->operator ?? '-' }}</td>
                                <td class="py-3 px-4">
                                    <div class="text-xs">
                                        {{ $count->created_at ? $count->created_at->format('Y-m-d H:i:s') : '-' }}
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
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
