<?php

use Livewire\Volt\Component;
use App\Models\InsBpmCount;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Livewire\Attributes\Url;


new class extends Component {
    public $view = "summary";
    public $dateFrom;
    public $dateTo;

    #[Url]
    public $plant;

    public $lastUpdated;
    public $summaryCards = [];
    public $rankingData = [];
    public $chartLabels = [];
    public $chartData = [];
    public $rankingDatav1 = [];

    public function mount()
    {
        // update menu
        $this->dispatch("update-menu", $this->view);
        
        // Set default dates
        $this->dateFrom = now()->subDays(7)->format('Y-m-d');
        $this->dateTo = now()->format('Y-m-d');
        $this->plant = '';
        
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

    public function loadData()
    {
        $from = Carbon::parse($this->dateFrom)->startOfDay();
        $to = Carbon::parse($this->dateTo)->endOfDay();

        // Calculate total emergency across all lines
        $totalEmergency = InsBpmCount::whereBetween('created_at', [$from, $to])
            ->when($this->plant, fn($q) => $q->where('plant', $this->plant))
            ->sum('incremental');

        // Get emergency count per line
        $emergencyPerLine = InsBpmCount::whereBetween('created_at', [$from, $to])
            ->when($this->plant, fn($q) => $q->where('plant', $this->plant))
            ->select('line', DB::raw('SUM(incremental) as total'))
            ->groupBy('line')
            ->orderByDesc('total')
            ->get();

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

        $this->lastUpdated = now()->format('m/d/Y, H:i:s');
    }

    public function loadRankingData()
    {
        $from = Carbon::parse($this->dateFrom)->startOfDay();
        $to = Carbon::parse($this->dateTo)->endOfDay();

        // Get ranking data - grouped by line and machine
        $this->rankingData = InsBpmCount::whereBetween('created_at', [$from, $to])
            ->when($this->plant, fn($q) => $q->where('plant', $this->plant))
            ->select('line', 'machine', DB::raw('SUM(incremental) as total_counter'))
            ->groupBy('line', 'machine')
            ->orderByDesc('total_counter')
            ->limit(16)
            ->get();
    }

    public function generateEmergencyChart()
    {
        $from = Carbon::parse($this->dateFrom)->startOfDay();
        $to = Carbon::parse($this->dateTo)->endOfDay();

        // Load Emergency Counter data (grouped by line and machine)
        $emergencyData = InsBpmCount::whereBetween('created_at', [$from, $to])
            ->when($this->plant, fn($q) => $q->where('plant', $this->plant))
            ->select(
                'line',
                'machine',
                DB::raw('SUM(incremental) as total_counter')
            )
            ->groupBy('line', 'machine')
            ->orderByDesc('total_counter')
            ->limit(20)
            ->get();

        // Format labels and extract data
        $labels = $emergencyData->map(function($item) {
            return $item->line . ' - Mesin ' . $item->machine;
        })->values()->toArray();
        
        $data = $emergencyData->pluck('total_counter')->map(function($value) {
            return (int) $value;
        })->values()->toArray();

        // Store in component properties
        $this->chartLabels = $labels;
        $this->chartData = $data;
        
        // Dispatch browser event to trigger chart refresh
        $this->dispatch('chart-data-updated')->self();
    }

    public function updated($property)
    {
        if (in_array($property, ['dateFrom', 'dateTo', 'plant'])) {
            $this->loadData();
            $this->generateEmergencyChart();
        }
    }
}; ?>

<div class="p-6 space-y-6">
    {{-- Header with Filters --}}
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
            <div>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">RENTANG</label>
                <div class="flex gap-2 mt-1">
                    <x-text-input wire:model.live="dateFrom" type="date" class="w-40" />
                    <x-text-input wire:model.live="dateTo" type="date" class="w-40" />
                </div>
            </div>
            <div>
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">PLANT</label>
                <x-select wire:model.live="plant" class="mt-1 w-32">
                    <option value="">All</option>
                    <option value="A">Plant A</option>
                    <option value="B">Plant B</option>
                    <option value="C">Plant C</option>
                    <option value="D">Plant D</option>
                    <option value="E">Plant E</option>
                    <option value="F">Plant F</option>
                    <option value="G">Plant G</option>
                    <option value="H">Plant H</option>
                    <option value="J">Plant J</option>
                </x-select>
            </div>
        </div>
        <div class="text-sm text-gray-600 dark:text-gray-400">
            <div>Last Updated</div>
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

                    initOrUpdateEmergencyChart(chartData) {
                        // Prevent multiple simultaneous operations
                        if (this.isDestroying) {
                            return;
                        }

                        const canvasEl = this.$refs.emergencyChartCanvas;
                        if (!canvasEl) {
                            return;
                        }

                        const labels = chartData.labels || [];
                        const data = chartData.data || [];

                        // Check if Chart.js is loaded
                        if (typeof Chart === 'undefined') {
                            return;
                        }

                        // Check if we have data
                        if (labels.length === 0 || data.length === 0) {
                            return;
                        }

                        // Destroy old chart if exists
                        if (this.emergencyChart) {
                            this.isDestroying = true;
                            try {
                                if (this.emergencyChart.ctx && this.emergencyChart.canvas) {
                                    this.emergencyChart.destroy();
                                }
                            } catch (e) {
                                // Silent fail
                            }
                            this.emergencyChart = null;
                            this.isDestroying = false;
                        }

                        const ctx = canvasEl.getContext('2d');
                        if (!ctx) {
                            return;
                        }
                        
                        try {
                            this.emergencyChart = new Chart(ctx, {
                                type: 'bar',
                                data: {
                                    labels: labels,
                                    datasets: [{
                                        label: 'Emergency Counter',
                                        data: data,
                                        backgroundColor: 'rgba(239, 68, 68, 0.8)',
                                        borderColor: 'rgba(239, 68, 68, 1)',
                                        borderWidth: 1
                                    }]
                                },
                                options: {
                                    indexAxis: 'y',
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: {
                                        legend: {
                                            display: false
                                        },
                                        tooltip: {
                                            callbacks: {
                                                label: function(context) {
                                                    return context.parsed.x + ' counts';
                                                }
                                            }
                                        }
                                    },
                                    scales: {
                                        x: {
                                            beginAtZero: true,
                                            title: {
                                                display: true,
                                                text: 'Counter'
                                            }
                                        },
                                        y: {
                                            title: {
                                                display: true,
                                                text: 'Line - Machine'
                                            }
                                        }
                                    }
                                }
                            });
                        } catch (e) {
                            // Silent fail
                        }
                    }
                }"
                wire:ignore
                x-init="
                    // Watch for data changes
                    $watch('$wire.chartLabels', () => {
                        const labels = $wire.chartLabels || [];
                        const data = $wire.chartData || [];
                        
                        if (labels.length > 0 && data.length > 0) {
                            $nextTick(() => {
                                initOrUpdateEmergencyChart({ labels, data });
                            });
                        }
                    });
                    
                    // Initial render if data already exists
                    $nextTick(() => {
                        const labels = $wire.chartLabels || [];
                        const data = $wire.chartData || [];
                        
                        if (labels.length > 0 && data.length > 0) {
                            initOrUpdateEmergencyChart({ labels, data });
                        }
                    });
                "
            >
                <div wire:ignore style="height: 500px; position: relative;">
                    <canvas x-ref="emergencyChartCanvas"></canvas>
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
