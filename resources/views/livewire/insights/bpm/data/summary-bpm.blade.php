<?php

use Livewire\Volt\Component;
use App\Models\InsBpmCount;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Livewire\Attributes\Url;


new class extends Component {
    public $view = "summary";
    
    #[Url]
    public $start_at;
    
    #[Url]
    public $end_at;
    
    #[Url]
    public $plant = 'G';
    
    #[Url]
    public $condition = 'all';

    public $lastUpdated;
    public $summaryCards = [];
    public $rankingData = [];
    public $chartLabels = [];
    public $chartData = [];
    public $chartDatasets = [];

    public function mount()
    {
        // update menu
        $this->dispatch("update-menu", $this->view);
        
        // Set default dates if not set
        if (! $this->start_at || ! $this->end_at) {
            $this->setToday();
        }
        
        // Load initial data
        $this->loadData();
        $this->generateEmergencyChart();
    }
    
    public function with(): array
    {
        return [
            'summaryCards' => $this->summaryCards,
            'rankingData' => $this->rankingData,
            'lastUpdated' => $this->lastUpdated,
        ];
    }
    
    public function setToday()
    {
        $this->start_at = now()->format('Y-m-d');
        $this->end_at = now()->format('Y-m-d');
        $this->loadData();
        $this->generateEmergencyChart();
    }

    public function setYesterday()
    {
        $this->start_at = now()->subDay()->format('Y-m-d');
        $this->end_at = now()->subDay()->format('Y-m-d');
        $this->loadData();
        $this->generateEmergencyChart();
    }

    public function setThisWeek()
    {
        $this->start_at = now()->startOfWeek()->format('Y-m-d');
        $this->end_at = now()->endOfWeek()->format('Y-m-d');
        $this->loadData();
        $this->generateEmergencyChart();
    }

    public function setLastWeek()
    {
        $this->start_at = now()->subWeek()->startOfWeek()->format('Y-m-d');
        $this->end_at = now()->subWeek()->endOfWeek()->format('Y-m-d');
        $this->loadData();
        $this->generateEmergencyChart();
    }

    public function setThisMonth()
    {
        $this->start_at = now()->startOfMonth()->format('Y-m-d');
        $this->end_at = now()->endOfMonth()->format('Y-m-d');
        $this->loadData();
        $this->generateEmergencyChart();
    }

    public function setLastMonth()
    {
        $this->start_at = now()->subMonth()->startOfMonth()->format('Y-m-d');
        $this->end_at = now()->subMonth()->endOfMonth()->format('Y-m-d');
        $this->loadData();
        $this->generateEmergencyChart();
    }

    public function loadData()
    {
        $from = Carbon::parse($this->start_at)->startOfDay();
        $to = Carbon::parse($this->end_at)->endOfDay();

        // Get all records and find latest for each line-machine-condition
        $allRecords = InsBpmCount::whereBetween('created_at', [$from, $to])
            ->when($this->plant, fn($q) => $q->where('plant', $this->plant))
            ->when($this->condition !== 'all', fn($q) => $q->where('condition', $this->condition))
            ->orderBy('created_at', 'desc')
            ->get();
        
        $latestRecords = $allRecords->groupBy(function($item) {
            return $item->line . '-' . $item->machine . '-' . $item->condition;
        })->map->first();
        
        // Calculate total emergency across all lines
        $totalEmergency = $latestRecords->sum('cumulative');

        // Get emergency count per line
        $emergencyPerLine = $latestRecords->groupBy('line')->map(function($items) {
            return (object) ['line' => $items->first()->line, 'total' => $items->sum('cumulative')];
        })->sortByDesc('total')->values();

        // Calculate highest, lowest and average
        $highest = $emergencyPerLine->first();
        $lowest = $emergencyPerLine->last();
        $average = $emergencyPerLine->count() > 0 
            ? round($emergencyPerLine->avg('total')) 
            : 0;

        $this->summaryCards = [
            [
                'label' => 'Total Emergency',
                'sublabel' => 'Semua Line',
                'value' => $totalEmergency,
                'color' => 'red',
                'icon' => 'emergency'
            ],
            [
                'label' => 'Tertinggi',
                'sublabel' => $highest ? $highest->line : '-',
                'value' => $highest ? $highest->total : 0,
                'color' => 'orange',
                'icon' => 'trending-up'
            ],
            [
                'label' => 'Rata-rata',
                'sublabel' => 'Per Line',
                'value' => $average,
                'color' => 'blue',
                'icon' => 'calendar'
            ],
            [
                'label' => 'Terendah',
                'sublabel' => $lowest ? $lowest->line : '-',
                'value' => $lowest ? $lowest->total : 0,
                'color' => 'green',
                'icon' => 'clock'
            ],
        ];

        // Load ranking data
        $this->loadRankingData();

        $this->lastUpdated = now()->format('n/j/Y, H:i.s');
    }

    public function loadRankingData()
    {
        $from = Carbon::parse($this->start_at)->startOfDay();
        $to = Carbon::parse($this->end_at)->endOfDay();

        // Get all records and find latest for each line-machine-condition
        $allRecords = InsBpmCount::whereBetween('created_at', [$from, $to])
            ->when($this->plant, fn($q) => $q->where('plant', $this->plant))
            ->when($this->condition !== 'all', fn($q) => $q->where('condition', $this->condition))
            ->orderBy('created_at', 'desc')
            ->get();
        
        $latestRecords = $allRecords->groupBy(function($item) {
            return $item->line . '-' . $item->machine . '-' . $item->condition;
        })->map->first();

        // Get ranking data - grouped by line and machine
        $this->rankingData = $latestRecords->groupBy(function($item) {
            return $item->line . '-' . $item->machine;
        })->map(function($items) {
            $first = $items->first();
            return (object) [
                'line' => $first->line,
                'machine' => $first->machine,
                'total_counter' => $items->sum('cumulative')
            ];
        })->sortByDesc('total_counter')->take(16)->values();
    }

    public function generateEmergencyChart()
    {
        $from = Carbon::parse($this->start_at)->startOfDay();
        $to = Carbon::parse($this->end_at)->endOfDay();

        if ($this->condition === 'all') {
            // Load Emergency Counter data with hot/cold breakdown
            $emergencyData = InsBpmCount::whereBetween('created_at', [$from, $to])
                ->when($this->plant, fn($q) => $q->where('plant', $this->plant))
                ->orderBy('created_at', 'desc')
                ->get()
                ->groupBy(function($item) {
                    return $item->line . '-' . $item->machine . '-' . $item->condition;
                })
                ->map->first();
            
            // Get unique line-machine combinations and calculate totals
            $lineMachines = $emergencyData->groupBy(function($item) {
                return $item->line . '-' . $item->machine;
            })->map(function($items, $key) {
                $parts = explode('-', $key);
                return [
                    'line' => $this->plant .$parts[0],
                    'machine' => $parts[1],
                    'total' => $items->sum('cumulative'),
                    'hot' => $items->where('condition', 'hot')->first()->cumulative ?? 0,
                    'cold' => $items->where('condition', 'cold')->first()->cumulative ?? 0,
                ];
            })->take(20)->values();
            
            // Format labels
            $labels = $lineMachines->map(function($item) {
                return $item['line'] . ' - Mesin ' . $item['machine'];
            })->toArray();
            
            // Store in component properties
            $this->chartLabels = $labels;
            $this->chartDatasets = [
                [
                    'label' => 'Hot',
                    'data' => $lineMachines->pluck('hot')->map(fn($v) => (int) $v)->toArray(),
                    'backgroundColor' => 'rgba(239, 68, 68, 0.8)',
                    'borderColor' => 'rgba(239, 68, 68, 1)',
                    'borderWidth' => 1,
                ],
                [
                    'label' => 'Cold',
                    'data' => $lineMachines->pluck('cold')->map(fn($v) => (int) $v)->toArray(),
                    'backgroundColor' => 'rgba(59, 130, 246, 0.8)',
                    'borderColor' => 'rgba(59, 130, 246, 1)',
                    'borderWidth' => 1,
                ]
            ];
        } else {
            // Load Emergency Counter data for specific condition
            $emergencyData = InsBpmCount::whereBetween('created_at', [$from, $to])
                ->when($this->plant, fn($q) => $q->where('plant', $this->plant))
                ->where('condition', $this->condition)
                ->orderBy('created_at', 'desc')
                ->get()
                ->groupBy(function($item) {
                    return $item->line . '-' . $item->machine;
                })
                ->map->first();

            // Format labels and extract data
            $sortedData = $emergencyData->sortByDesc('cumulative')->take(20);
            
            $labels = $sortedData->map(function($item) {
                return $item->line . ' - Mesin ' . $item->machine;
            })->values()->toArray();
            
            $data = $sortedData->pluck('cumulative')->map(function($value) {
                return (int) $value;
            })->values()->toArray();

            // Determine color based on condition
            $conditionColor = $this->condition === 'hot' 
                ? ['bg' => 'rgba(239, 68, 68, 0.8)', 'border' => 'rgba(239, 68, 68, 1)']
                : ['bg' => 'rgba(59, 130, 246, 0.8)', 'border' => 'rgba(59, 130, 246, 1)'];
            
            // Store in component properties
            $this->chartLabels = $labels;
            $this->chartDatasets = [
                [
                    'label' => ucfirst($this->condition),
                    'data' => $data,
                    'backgroundColor' => $conditionColor['bg'],
                    'borderColor' => $conditionColor['border'],
                    'borderWidth' => 1,
                ]
            ];
        }
        
        // Dispatch browser event to trigger chart refresh
        $this->dispatch('chart-data-updated', [
            'labels' => $this->chartLabels,
            'datasets' => $this->chartDatasets
        ]);
    }

    public function updated($property)
    {
        if (in_array($property, ['start_at', 'end_at', 'plant', 'condition'])) {
            $this->loadData();
            $this->generateEmergencyChart();
        }
    }
}; ?>

<div class="p-6 space-y-6">
    {{-- Header with Filters --}}
    <div class="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
        <div class="flex flex-col sm:flex-row gap-4 items-start sm:items-end flex-1">
            <div>
                <div class="flex mb-2 text-xs text-neutral-500">
                    <div class="flex">
                        <x-dropdown align="left" width="48">
                            <x-slot name="trigger">
                                <x-text-button class="uppercase ml-3">
                                    {{ __("RENTANG") }}
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
            <div>
                <label class="block text-sm font-medium mb-2">{{ __('PLANT') }}</label>
                <select wire:model.live="plant" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm">
                    <option value="">All</option>
                    <option value="A">A</option>
                    <option value="B">B</option>
                    <option value="C">C</option>
                    <option value="D">D</option>
                    <option value="E">E</option>
                    <option value="F">F</option>
                    <option value="G">G</option>
                    <option value="H">H</option>
                    <option value="I">I</option>
                    <option value="J">J</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-2">{{ __('CONDITION') }}</label>
                <select wire:model.live="condition" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm">
                    <option value="all">All</option>
                    <option value="hot">Hot</option>
                    <option value="cold">Cold</option>
                </select>
            </div>
        </div>
        <div class="text-sm text-gray-600 dark:text-gray-400">
            <div>{{ __('Last Updated') }}</div>
            <div class="font-semibold">{{ $lastUpdated }}</div>
        </div>
    </div>

    {{-- Main Content Grid --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Left Column: Emergency Counter Chart --}}
        <div class="lg:col-span-2 bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold">Emergency Counter</h2>
            </div>
            <div
                x-data="{
                    emergencyChart: null,
                    isDestroying: false,
                    hasData: true,
                    initTimeout: null,

                    destroyChart() {
                        if (this.isDestroying) return;
                        
                        this.isDestroying = true;
                        
                        // Clear any pending initialization
                        if (this.initTimeout) {
                            clearTimeout(this.initTimeout);
                            this.initTimeout = null;
                        }
                        
                        const canvasEl = this.$refs.emergencyChartCanvas;
                        if (canvasEl) {
                            const existingChart = Chart.getChart(canvasEl);
                            if (existingChart) {
                                try {
                                    existingChart.destroy();
                                } catch (e) {
                                    console.log('Error destroying chart:', e);
                                }
                            }
                        }
                        
                        this.emergencyChart = null;
                        this.isDestroying = false;
                    },

                    initOrUpdateEmergencyChart(chartData) {
                        // Clear any pending initialization
                        if (this.initTimeout) {
                            clearTimeout(this.initTimeout);
                            this.initTimeout = null;
                        }

                        // Prevent operations during destruction
                        if (this.isDestroying) {
                            return;
                        }

                        const canvasEl = this.$refs.emergencyChartCanvas;
                        if (!canvasEl) {
                            return;
                        }

                        const labels = chartData?.labels || [];
                        const datasets = chartData?.datasets || [];

                        // Check if Chart.js is loaded
                        if (typeof Chart === 'undefined') {
                            return;
                        }

                        // Check if we have data
                        if (labels.length === 0 || datasets.length === 0) {
                            this.hasData = false;
                            this.destroyChart();
                            return;
                        }

                        this.hasData = true;

                        // Destroy existing chart
                        this.destroyChart();

                        // Wait a bit to ensure clean state before creating new chart
                        this.initTimeout = setTimeout(() => {
                            if (!this.hasData || this.isDestroying) {
                                return;
                            }

                            const ctx = canvasEl?.getContext('2d');
                            if (!ctx) {
                                return;
                            }
                            
                            try {
                                this.emergencyChart = new Chart(ctx, {
                                    type: 'bar',
                                    data: {
                                        labels: labels,
                                        datasets: datasets
                                    },
                                    options: {
                                        indexAxis: 'y',
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        animation: {
                                            duration: 300
                                        },
                                        plugins: {
                                            legend: {
                                                display: datasets.length > 1,
                                                position: 'top'
                                            },
                                            tooltip: {
                                                callbacks: {
                                                    label: function(context) {
                                                        return context.dataset.label + ': ' + context.parsed.x + ' counts';
                                                    }
                                                }
                                            },
                                            datalabels: {
                                                display: false
                                            }
                                        },
                                        scales: {
                                            x: {
                                                stacked: datasets.length > 1,
                                                beginAtZero: true,
                                                title: {
                                                    display: true,
                                                    text: 'Counter'
                                                },
                                                ticks: {
                                                    stepSize: 1,
                                                    callback: function(value) {
                                                        if (Number.isInteger(value)) {
                                                            return value.toLocaleString();
                                                        }
                                                        return '';
                                                    }
                                                }
                                            },
                                            y: {
                                                stacked: datasets.length > 1,
                                                title: {
                                                    display: true,
                                                    text: 'Line - Machine'
                                                }
                                            }
                                        }
                                    }
                                });
                            } catch (e) {
                                console.error('Chart creation error:', e);
                                this.hasData = false;
                            }
                        }, 50);
                    }
                }"
                x-init="
                    // Initial render with delay
                    setTimeout(() => {
                        const labels = @js($this->chartLabels);
                        const datasets = @js($this->chartDatasets);
                        
                        initOrUpdateEmergencyChart({ labels, datasets });
                    }, 100);
                "
                @chart-data-updated.window="
                    const eventData = $event.detail;
                    if (eventData) {
                        initOrUpdateEmergencyChart(eventData);
                    }
                "
            >
                <div wire:ignore style="height: 500px; position: relative;">
                    <canvas x-ref="emergencyChartCanvas" x-show="hasData"></canvas>
                    <div x-show="!hasData" class="flex flex-col items-center justify-center h-full text-gray-500">
                        <svg class="w-16 h-16 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                        <p class="text-lg font-medium">No Data Available</p>
                        <p class="text-sm text-gray-400 mt-2">Try adjusting your filters or date range</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Right Column: Summary Cards and Ranking --}}
        <div class="space-y-6">
            {{-- Summary Cards --}}
            <div class="grid grid-cols-2 gap-4">
                @foreach($summaryCards as $card)
                <div class="bg-{{ $card['color'] }}-500 text-white rounded-lg p-4">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <div class="text-3xl font-bold">{{ number_format($card['value']) }}</div>
                            <div class="text-sm mt-1 font-medium">{{ $card['label'] }}</div>
                            <div class="text-xs mt-0.5 opacity-80">{{ $card['sublabel'] }}</div>
                        </div>
                        <div class="text-2xl opacity-75">
                            @if($card['icon'] === 'emergency')
                                <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                            @elseif($card['icon'] === 'trending-up')
                                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                                </svg>
                            @elseif($card['icon'] === 'calendar')
                                <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/>
                                </svg>
                            @else
                                <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
                                </svg>
                            @endif
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- Ranking Table --}}
            <div class="bg-white dark:bg-neutral-800 rounded-lg shadow">
                <div class="p-4 border-b border-neutral-200 dark:border-neutral-700">
                    <h2 class="font-semibold">Ranking Emergency Counter</h2>
                </div>
                <div class="overflow-auto max-h-96">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-neutral-700 sticky top-0">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300">RANK</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300">LINE</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300">MESIN</th>
                                <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-300">COUNTER</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @forelse($rankingData as $index => $item)
                            <tr class="hover:bg-gray-50 dark:hover:bg-neutral-700">
                                <td class="px-4 py-2">{{ $index + 1 }}</td>
                                <td class="px-4 py-2">{{ $item->line }}</td>
                                <td class="px-4 py-2">{{ $item->machine }}</td>
                                <td class="px-4 py-2 text-right font-semibold text-red-600">{{ number_format($item->total_counter) }}</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-gray-500">
                                    <div class="flex flex-col items-center justify-center">
                                        <svg class="w-12 h-12 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                        </svg>
                                        <p class="text-sm">No data available</p>
                                        <p class="text-xs text-gray-400 mt-1">Try adjusting your date range</p>
                                    </div>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
