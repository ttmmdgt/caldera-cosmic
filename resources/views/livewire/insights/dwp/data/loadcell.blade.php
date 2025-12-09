<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use App\Models\InsDwpLoadcell;
use App\Traits\HasDateRangeFilter;
use Carbon\Carbon;

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
    public string $machine = "";

    #[Url]
    public string $position = "";

    #[Url]
    public string $result = "";

    public string $view = "loadcell";
    public array $latestData = [];
    public array $sensorValues = [];

    public function mount()
    {
        // Always set to today for loadcell view
        $this->setToday();

        // update menu
        $this->dispatch("update-menu", $this->view);
        
        // Load latest data
        $this->loadLatestData();
    }

    /**
     * Get filtered loadcell query
     */
    private function getLoadcellQuery()
    {
        $start = Carbon::parse($this->start_at);
        $end = Carbon::parse($this->end_at)->endOfDay();

        $query = InsDwpLoadcell::whereBetween("created_at", [$start, $end]);

        if ($this->plant) {
            $query->where("plant", "like", "%" . strtoupper(trim($this->plant)) . "%");
        }

        if ($this->line) {
            $query->where("line", strtoupper(trim($this->line)));
        }

        if ($this->machine) {
            $query->where("machine_name", $this->machine);
        }

        if ($this->position) {
            $query->where("position", "like", "%" . $this->position . "%");
        }

        if ($this->result) {
            $query->where("result", $this->result);
        }

        return $query;
    }

    /**
     * Load latest loadcell data
     */
    public function loadLatestData()
    {
        // Reset data first
        $this->latestData = [];
        $this->sensorValues = [];
        
        $query = $this->getLoadcellQuery();
        $latest = $query->latest()->first();
        
        if ($latest) {
            $this->latestData = $latest->toArray();
            $loadcellData = json_decode($latest->loadcell_data, true);
            
            // Extract sensor values from ALL cycles and calculate median
            if (isset($loadcellData['metadata']['cycles'])) {
                $allSensorData = [];
                
                // Collect all values from all cycles for each sensor
                foreach ($loadcellData['metadata']['cycles'] as $cycle) {
                    if (isset($cycle['sensors'])) {
                        foreach ($cycle['sensors'] as $sensorName => $values) {
                            if (!isset($allSensorData[$sensorName])) {
                                $allSensorData[$sensorName] = [];
                            }
                            // Collect all non-zero values
                            $filteredValues = array_filter($values, function($v) { return $v > 0; });
                            $allSensorData[$sensorName] = array_merge($allSensorData[$sensorName], $filteredValues);
                        }
                    }
                }
                
                // Calculate median for each sensor
                foreach ($allSensorData as $sensorName => $values) {
                    if (!empty($values)) {
                        sort($values);
                        $count = count($values);
                        $mid = floor($count / 2);
                        
                        if ($count % 2 === 0) {
                            $median = ($values[$mid - 1] + $values[$mid]) / 2;
                        } else {
                            $median = $values[$mid];
                        }
                        
                        $this->sensorValues[$sensorName] = $median;
                    } else {
                        $this->sensorValues[$sensorName] = 0;
                    }
                }
            }
        }
    }

    #[On('refresh-loadcell')]
    public function refresh()
    {
        $this->loadLatestData();
    }

    public function updated($property)
    {
        if (in_array($property, ['start_at', 'end_at', 'line', 'plant', 'machine', 'position', 'result'])) {
            $this->loadLatestData();
            $this->dispatch('$refresh');
        }
    }
}; ?>

<div>
    <!-- Filters Section -->
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
                        <option value="">{{ __("All") }}</option>
                        <option value="Plant G">G</option>
                        <option value="Plant A">A</option>
                    </x-select>
                </div>
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Line") }}</label>
                    <x-select wire:model.live="line" class="w-full lg:w-32">
                        <option value="">{{ __("All") }}</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                        <option value="4">4</option>
                        <option value="5">5</option>
                    </x-select>
                </div>
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Machine") }}</label>
                    <x-select wire:model.live="machine" class="w-full lg:w-32">
                        <option value="">{{ __("All") }}</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                        <option value="4">4</option>
                    </x-select>
                </div>
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Position") }}</label>
                    <x-select wire:model.live="position" class="w-full lg:w-32">
                        <option value="">{{ __("All") }}</option>
                        <option value="Left">Left</option>
                        <option value="Right">Right</option>
                    </x-select>
                </div>
                <div>
                    <label class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Result") }}</label>
                    <x-select wire:model.live="result" class="w-full lg:w-32">
                        <option value="">{{ __("All") }}</option>
                        <option value="std">Standard</option>
                        <option value="fail">Not Standard</option>
                    </x-select>
                </div>
            </div>
        </div>
    </div>

    <!-- Loadcell Visualization -->
    <div class="p-0 sm:p-1">
        <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-6">
            @if($latestData)
                <div class="mb-6">
                    <h2 class="text-xl font-semibold text-neutral-800 dark:text-neutral-200 mb-2">
                        {{ __('Loadcell Pressure Visualization') }}
                    </h2>
                    <div class="text-sm text-neutral-600 dark:text-neutral-400">
                        <span class="font-medium">{{ __('Plant') }}:</span> {{ $latestData['plant'] ?? '-' }} |
                        <span class="font-medium">{{ __('Line') }}:</span> {{ $latestData['line'] ?? '-' }} |
                        <span class="font-medium">{{ __('Machine') }}:</span> {{ $latestData['machine_name'] ?? '-' }} |
                        <span class="font-medium">{{ __('Position') }}:</span> {{ $latestData['position'] ?? '-' }}
                    </div>
                    <div class="text-xs text-neutral-500 dark:text-neutral-500 mt-1">
                        {{ __('Recorded at') }}: {{ $latestData['recorded_at'] ? \Carbon\Carbon::parse($latestData['recorded_at'])->format('Y-m-d H:i:s') : '-' }}
                    </div>
                </div>

                <!-- Shoe Visualization -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Left Foot -->
                    <div>
                        <h3 class="text-center text-lg font-medium text-neutral-700 dark:text-neutral-300 mb-4">Left</h3>
                        <div class="relative w-full max-w-md mx-auto">
                            <!-- Background shoe image -->
                            <svg viewBox="0 0 400 600" class="w-full h-auto">
                                <!-- Background shoe image (flipped horizontally for left foot) -->
                                <image href="{{ asset('pic_dwp.png') }}" x="0" y="0" width="400" height="600" preserveAspectRatio="xMidYMid meet" transform="translate(400, 0) scale(-1, 1)"/>
                                
                                <!-- Sensor positions with values -->
                                <!-- Top sensors: T1_L -->
                                <g class="sensor" data-sensor="T1_L">
                                    <rect x="160" y="90" width="80" height="50" rx="8" 
                                          class="fill-blue-500 dark:fill-blue-600" opacity="0.9"/>
                                    <text x="200" y="110" text-anchor="middle" class="fill-white text-xs font-bold">T1_L</text>
                                    <text x="200" y="128" text-anchor="middle" class="fill-white text-lg font-bold">
                                        {{ number_format($sensorValues['T1_L'] ?? 0, 1) }}
                                    </text>
                                </g>
                                
                                <!-- Center top sensors: C1_L -->
                                <g class="sensor" data-sensor="C1_L">
                                    <rect x="160" y="210" width="80" height="50" rx="8" 
                                          class="fill-blue-500 dark:fill-blue-600" opacity="0.9"/>
                                    <text x="200" y="230" text-anchor="middle" class="fill-white text-xs font-bold">C1_L</text>
                                    <text x="200" y="248" text-anchor="middle" class="fill-white text-lg font-bold">
                                        {{ number_format($sensorValues['C1_L'] ?? 0, 1) }}
                                    </text>
                                </g>
                                
                                <!-- Center bottom sensors: C2_L -->
                                <g class="sensor" data-sensor="C2_L">
                                    <rect x="160" y="390" width="80" height="50" rx="8" 
                                          class="fill-blue-500 dark:fill-blue-600" opacity="0.9"/>
                                    <text x="200" y="410" text-anchor="middle" class="fill-white text-xs font-bold">C2_L</text>
                                    <text x="200" y="428" text-anchor="middle" class="fill-white text-lg font-bold">
                                        {{ number_format($sensorValues['C2_L'] ?? 0, 1) }}
                                    </text>
                                </g>
                                
                                <!-- Heel sensors: H1_L -->
                                <g class="sensor" data-sensor="H1_L">
                                    <rect x="160" y="510" width="80" height="50" rx="8" 
                                          class="fill-blue-500 dark:fill-blue-600" opacity="0.9"/>
                                    <text x="200" y="530" text-anchor="middle" class="fill-white text-xs font-bold">H1_L</text>
                                    <text x="200" y="548" text-anchor="middle" class="fill-white text-lg font-bold">
                                        {{ number_format($sensorValues['H1_L'] ?? 0, 1) }}
                                    </text>
                                </g>
                                
                                <!-- Left side sensors -->
                                <!-- L1_L -->
                                <g class="sensor" data-sensor="L1_L">
                                    <rect x="40" y="180" width="80" height="50" rx="8" 
                                          class="fill-blue-500 dark:fill-blue-600" opacity="0.9"/>
                                    <text x="80" y="200" text-anchor="middle" class="fill-white text-xs font-bold">L1_L</text>
                                    <text x="80" y="218" text-anchor="middle" class="fill-white text-lg font-bold">
                                        {{ number_format($sensorValues['L1_L'] ?? 0, 1) }}
                                    </text>
                                </g>
                                
                                <!-- M1_L -->
                                <g class="sensor" data-sensor="M1_L">
                                    <rect x="280" y="180" width="80" height="50" rx="8" 
                                          class="fill-blue-500 dark:fill-blue-600" opacity="0.9"/>
                                    <text x="320" y="200" text-anchor="middle" class="fill-white text-xs font-bold">M1_L</text>
                                    <text x="320" y="218" text-anchor="middle" class="fill-white text-lg font-bold">
                                        {{ number_format($sensorValues['M1_L'] ?? 0, 1) }}
                                    </text>
                                </g>
                                
                                <!-- L2_L -->
                                <g class="sensor" data-sensor="L2_L">
                                    <rect x="60" y="450" width="80" height="50" rx="8" 
                                          class="fill-blue-500 dark:fill-blue-600" opacity="0.9"/>
                                    <text x="100" y="470" text-anchor="middle" class="fill-white text-xs font-bold">L2_L</text>
                                    <text x="100" y="488" text-anchor="middle" class="fill-white text-lg font-bold">
                                        {{ number_format($sensorValues['L2_L'] ?? 0, 1) }}
                                    </text>
                                </g>
                                
                                <!-- M2_L -->
                                <g class="sensor" data-sensor="M2_L">
                                    <rect x="260" y="450" width="80" height="50" rx="8" 
                                          class="fill-blue-500 dark:fill-blue-600" opacity="0.9"/>
                                    <text x="300" y="470" text-anchor="middle" class="fill-white text-xs font-bold">M2_L</text>
                                    <text x="300" y="488" text-anchor="middle" class="fill-white text-lg font-bold">
                                        {{ number_format($sensorValues['M2_L'] ?? 0, 1) }}
                                    </text>
                                </g>
                            </svg>
                        </div>
                    </div>

                    <!-- Right Foot -->
                    <div>
                        <h3 class="text-center text-lg font-medium text-neutral-700 dark:text-neutral-300 mb-4">Right</h3>
                        <div class="w-full max-w-md mx-auto">
                            <!-- Background shoe image -->
                            <svg viewBox="0 0 400 600" class="w-full h-auto">
                                <!-- Background shoe image -->
                                <image href="{{ asset('pic_dwp.png') }}" x="0" y="0" width="400" height="600" preserveAspectRatio="xMidYMid meet"/>
                                
                                <!-- Sensor positions with values -->
                                <!-- Top sensors: T1_R -->
                                <g class="sensor" data-sensor="T1_R">
                                    <rect x="160" y="90" width="80" height="50" rx="8" 
                                          class="fill-gray-500 dark:fill-gray-600" opacity="0.9"/>
                                    <text x="200" y="110" text-anchor="middle" class="fill-white text-xs font-bold">T1_R</text>
                                    <text x="200" y="128" text-anchor="middle" class="fill-white text-lg font-bold">
                                        {{ number_format($sensorValues['T1_R'] ?? 0, 1) }}
                                    </text>
                                </g>
                                
                                <!-- Center top sensors: C1_R -->
                                <g class="sensor" data-sensor="C1_R">
                                    <rect x="160" y="210" width="80" height="50" rx="8" 
                                          class="fill-gray-500 dark:fill-gray-600" opacity="0.9"/>
                                    <text x="200" y="230" text-anchor="middle" class="fill-white text-xs font-bold">C1_R</text>
                                    <text x="200" y="248" text-anchor="middle" class="fill-white text-lg font-bold">
                                        {{ number_format($sensorValues['C1_R'] ?? 0, 1) }}
                                    </text>
                                </g>
                                
                                <!-- Center bottom sensors: C2_R -->
                                <g class="sensor" data-sensor="C2_R">
                                    <rect x="160" y="390" width="80" height="50" rx="8" 
                                          class="fill-gray-500 dark:fill-gray-600" opacity="0.9"/>
                                    <text x="200" y="410" text-anchor="middle" class="fill-white text-xs font-bold">C2_R</text>
                                    <text x="200" y="428" text-anchor="middle" class="fill-white text-lg font-bold">
                                        {{ number_format($sensorValues['C2_R'] ?? 0, 1) }}
                                    </text>
                                </g>
                                
                                <!-- Heel sensors: H1_R -->
                                <g class="sensor" data-sensor="H1_R">
                                    <rect x="160" y="510" width="80" height="50" rx="8" 
                                          class="fill-gray-500 dark:fill-gray-600" opacity="0.9"/>
                                    <text x="200" y="530" text-anchor="middle" class="fill-white text-xs font-bold">H1_R</text>
                                    <text x="200" y="548" text-anchor="middle" class="fill-white text-lg font-bold">
                                        {{ number_format($sensorValues['H1_R'] ?? 0, 1) }}
                                    </text>
                                </g>
                                
                                <!-- Right side sensors -->
                                <!-- M1_R -->
                                <g class="sensor" data-sensor="M1_R">
                                    <rect x="40" y="180" width="80" height="50" rx="8" 
                                          class="fill-gray-500 dark:fill-gray-600" opacity="0.9"/>
                                    <text x="80" y="200" text-anchor="middle" class="fill-white text-xs font-bold">M1_R</text>
                                    <text x="80" y="218" text-anchor="middle" class="fill-white text-lg font-bold">
                                        {{ number_format($sensorValues['M1_R'] ?? 0, 1) }}
                                    </text>
                                </g>
                                
                                <!-- L1_R -->
                                <g class="sensor" data-sensor="L1_R">
                                    <rect x="280" y="180" width="80" height="50" rx="8" 
                                          class="fill-gray-500 dark:fill-gray-600" opacity="0.9"/>
                                    <text x="320" y="200" text-anchor="middle" class="fill-white text-xs font-bold">L1_R</text>
                                    <text x="320" y="218" text-anchor="middle" class="fill-white text-lg font-bold">
                                        {{ number_format($sensorValues['L1_R'] ?? 0, 1) }}
                                    </text>
                                </g>
                                
                                <!-- M2_R -->
                                <g class="sensor" data-sensor="M2_R">
                                    <rect x="60" y="450" width="80" height="50" rx="8" 
                                          class="fill-gray-500 dark:fill-gray-600" opacity="0.9"/>
                                    <text x="100" y="470" text-anchor="middle" class="fill-white text-xs font-bold">M2_R</text>
                                    <text x="100" y="488" text-anchor="middle" class="fill-white text-lg font-bold">
                                        {{ number_format($sensorValues['M2_R'] ?? 0, 1) }}
                                    </text>
                                </g>
                                
                                <!-- L2_R -->
                                <g class="sensor" data-sensor="L2_R">
                                    <rect x="260" y="450" width="80" height="50" rx="8" 
                                          class="fill-gray-500 dark:fill-gray-600" opacity="0.9"/>
                                    <text x="300" y="470" text-anchor="middle" class="fill-white text-xs font-bold">L2_R</text>
                                    <text x="300" y="488" text-anchor="middle" class="fill-white text-lg font-bold">
                                        {{ number_format($sensorValues['L2_R'] ?? 0, 1) }}
                                    </text>
                                </g>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Legend -->
                <div class="mt-8 pt-6 border-t border-neutral-200 dark:border-neutral-700">
                    <h4 class="text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-3">{{ __('Sensor Legend') }}</h4>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
                        <div class="flex items-center gap-2">
                            <div class="w-4 h-4 bg-blue-500 rounded"></div>
                            <span class="text-neutral-600 dark:text-neutral-400">{{ __('Left Sensors') }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-4 h-4 bg-gray-500 rounded"></div>
                            <span class="text-neutral-600 dark:text-neutral-400">{{ __('Right Sensors') }}</span>
                        </div>
                        <div class="text-neutral-600 dark:text-neutral-400">
                            <span class="font-medium">T:</span> Toe ({{ __('Toe') }})
                        </div>
                        <div class="text-neutral-600 dark:text-neutral-400">
                            <span class="font-medium">C:</span> Center
                        </div>
                        <div class="text-neutral-600 dark:text-neutral-400">
                            <span class="font-medium">H:</span> Heel
                        </div>
                        <div class="text-neutral-600 dark:text-neutral-400">
                            <span class="font-medium">M:</span> Medial
                        </div>
                        <div class="text-neutral-600 dark:text-neutral-400">
                            <span class="font-medium">L:</span> Lateral
                        </div>
                        <div class="text-neutral-600 dark:text-neutral-400">
                            {{ __('Unit') }}: kg
                        </div>
                    </div>
                </div>
            @else
                <div class="py-20 text-center">
                    <div class="text-neutral-300 dark:text-neutral-700 text-5xl mb-3">
                        <i class="icon-database"></i>
                    </div>
                    <div class="text-neutral-400 dark:text-neutral-600">{{ __('No loadcell data available for the selected filters') }}</div>
                </div>
            @endif
        </div>
    </div>

    <!-- Auto refresh -->
    <div x-data="{ interval: null }" 
         x-init="interval = setInterval(() => { $wire.call('refresh') }, 5000)"
         x-destroy="clearInterval(interval)">
    </div>
</div>
