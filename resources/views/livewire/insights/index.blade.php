<?php

use App\Models\InsStcMLog;
use App\Models\InsStcDSum;
use App\Models\InsStcMachine;
use App\Models\InsOmvMetric;
use App\Models\InsCtcMetric;
use App\Models\InsRdcTest;
use App\Models\InsLdcHide;
use App\Models\InsClmRecord;
use App\Models\InsDwpCount;
use App\Models\InsBpmCount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;

new #[Layout("layouts.app")] class extends Component {
    public int $stc_machines_count = 0;
    public int $stc_d_sums_recent = 0;
    public int $omv_lines_recent = 0;
    public int $ctc_lines_recent = 0;
    public int $rdc_machines_recent = 0;
    public int $ldc_codes_recent = 0;
    public int $dwp_lines_recent = 0;
    public int $bpm_lines_recent = 0;

    // Climate data properties
    public float|null $temperature_latest = null;
    public float|null $humidity_latest = null;
    public bool $climate_data_stale = false;

    public bool $isLoading = true;

    public function mount()
    {
        // Fast initial load - defer expensive operations
    }

    public function loadMetrics()
    {
        $this->calculateMetrics();
        $this->isLoading = false;
    }

    private function pingStcMachine(): int
    {
        $count = 0;
        $machines = InsStcMachine::all();
        foreach ($machines as $machine) {
            if (strpos($machine->ip_address, "127.") !== 0) {
                try {
                    exec("ping -n 1 " . $machine->ip_address, $output, $status);
                    if ($status === 0) {
                        ++$count;
                    }
                } catch (\Exception $e) {
                    $this->js("console.log(" . $e->getMessage() . ")");
                }
            }
        }

        return $count;
    }

    private function getCachedStcMCount(): int
    {
        // Increase cache time to 2 hours for expensive ping operation
        return Cache::remember("stc_machines_count", now()->addHours(2), function () {
            return $this->pingStcMachine();
        });
    }

    private function getCachedStcMLogs(): int
    {
        return Cache::remember("stc_m_logs_recent", now()->addMinutes(30), function () {
            $timeWindow = Carbon::now()->subHours(5);
            return InsStcMLog::where("updated_at", ">=", $timeWindow)
                ->distinct("ins_stc_machine_id")
                ->count("ins_stc_machine_id");
        });
    }

    private function getCachedStcDSums(): int
    {
        return Cache::remember("stc_d_sums_recent", now()->addMinutes(30), function () {
            $timeWindow = Carbon::now()->subHours(5);
            return InsStcDSum::where("updated_at", ">=", $timeWindow)
                ->distinct("ins_stc_machine_id")
                ->count("ins_stc_machine_id");
        });
    }

    private function getCachedOmvLines(): int
    {
        return Cache::remember("omv_lines_recent", now()->addMinutes(30), function () {
            $timeWindow = Carbon::now()->subMinutes(60);
            return InsOmvMetric::where("updated_at", ">=", $timeWindow)
                ->distinct("line")
                ->count("line");
        });
    }

    private function getCachedCtcLines(): int
    {
        return Cache::remember("ctc_lines_recent", now()->addMinutes(30), function () {
            // Mock data for now - will be replaced with actual CTC model
            $timeWindow = Carbon::now()->subHours(2);
            return InsCtcMetric::where("updated_at", ">=", $timeWindow)
                ->distinct("ins_ctc_machine_id")
                ->count("ins_ctc_machine_id");
        });
    }

    private function getCachedRdcMachines(): int
    {
        return Cache::remember("rdc_machines_recent", now()->addMinutes(30), function () {
            $timeWindow = Carbon::now()->subMinutes(60);
            return InsRdcTest::where("updated_at", ">=", $timeWindow)
                ->distinct("ins_rdc_machine_id")
                ->count("ins_rdc_machine_id");
        });
    }

    private function getCachedLdcCodes(): int
    {
        return Cache::remember("ldc_codes_recent", now()->addMinutes(30), function () {
            $timeWindow = Carbon::now()->subMinutes(60);
            $validCodes = ["XA", "XB", "XC", "XD"];

            $recentCodes = InsLdcHide::where("updated_at", ">=", $timeWindow)
                ->get()
                ->map(function ($hide) {
                    preg_match("/X[A-D]/", $hide->code, $matches);
                    return $matches[0] ?? null;
                })
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            return count(array_intersect($validCodes, $recentCodes));
        });
    }

    private function getCachedDwpLines(): int
    {
        return Cache::remember("dwp_lines_recent", now()->addMinutes(10), function () {
            $timeWindow = Carbon::now()->subMinutes(1);
            return InsDwpCount::where("updated_at", ">=", $timeWindow)
                ->distinct("line")
                ->count("line");
        });
    }

    private function getLatestClimateData(): void
    {
        // Cache climate data for 5 minutes
        $climateData = Cache::remember("climate_data_ip", now()->addMinutes(5), function () {
            return InsClmRecord::where("location", "ip")
                ->orderBy("created_at", "desc")
                ->first();
        });

        if ($climateData) {
            $this->temperature_latest = $climateData->temperature;
            $this->humidity_latest = $climateData->humidity;

            // Check if data is stale (older than 3 hours)
            $threeHoursAgo = Carbon::now()->subHours(3);
            $this->climate_data_stale = $climateData->created_at->isBefore($threeHoursAgo);
        } else {
            // No data available
            $this->temperature_latest = null;
            $this->humidity_latest = null;
            $this->climate_data_stale = false;
        }
    }

    public function calculateMetrics()
    {
        $this->stc_machines_count = $this->getCachedStcMCount();
        $this->stc_d_sums_recent = $this->getCachedStcDSums();
        $this->omv_lines_recent = $this->getCachedOmvLines();
        $this->ctc_lines_recent = $this->getCachedCtcLines();
        $this->rdc_machines_recent = $this->getCachedRdcMachines();
        $this->ldc_codes_recent = $this->getCachedLdcCodes();
        $this->dwp_lines_recent = $this->getCachedDwpLines();
        $this->bpm_lines_recent = $this->getCachedBpmLines();

        // Get fresh climate data (no caching)
        $this->getLatestClimateData();
    }

    private function getCachedBpmLines(): int
    {
        return Cache::remember("bpm_lines_recent", now()->addMinutes(30), function () {
            $timeWindow = Carbon::now()->subHours(8);
            return InsBpmCount::where("updated_at", ">=", $timeWindow)
                ->distinct("line")
                ->count("line");
        });
    }

    #[On("recalculate")]
    public function recalculate()
    {
        Cache::forget("stc_machines_count");
        Cache::forget("stc_d_sums_recent");
        Cache::forget("omv_lines_recent");
        Cache::forget("ctc_lines_recent");
        Cache::forget("rdc_machines_recent");
        Cache::forget("ldc_codes_recent");
        Cache::forget("dwp_lines_recent");
        Cache::forget("bpm_lines_recent");
        Cache::forget("climate_data_ip");
        $this->isLoading = true;
        $this->loadMetrics();
    }
};

?>

<div wire:init="loadMetrics" wire:poll.900s id="content" class="py-12 text-neutral-800 dark:text-neutral-200">
    <x-slot name="title">{{ __("Wawasan") }}</x-slot>
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
        <div class="relative text-neutral h-32 sm:rounded-lg overflow-hidden mb-12">
            <img class="dark:invert absolute top-0 left-0 w-full h-full object-cover opacity-70" src="/insight-banner.jpg" />
            <div class="absolute top-0 left-0 flex h-full items-center px-4 lg:px-8 text-neutral-500">
                <div>
                    <div wire:click="recalculate" class="text-2xl mb-2 font-medium">{{ __("Wawasan") }}</div>
                    <div>{{ __("Platform analitik untuk proses manufaktur yang lebih terkendali.") }}</div>
                </div>
            </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="flex flex-col gap-6">
                <div>
                    <h1 class="uppercase text-sm text-neutral-500 mb-4 px-8">{{ __("Sistem Rubber Terintegrasi") }}</h1>
                    <div class="bg-white dark:bg-neutral-800 shadow overflow-hidden sm:rounded-lg divide-y divide-neutral-200 dark:text-white dark:divide-neutral-700">
                        <a href="{{ route('insights.omv.index') }}" class="block hover:bg-caldy-500 hover:bg-opacity-10" wire:navigate>
                            <div class="flex items-center">
                                <div class="px-6 py-3">
                                    <img src="/ink-omv.svg" class="w-16 h-16 dark:invert" />
                                </div>
                                <div class="grow">
                                    <div class="text-lg font-medium text-neutral-900 dark:text-neutral-100">{{ __("Pemantauan open mill") }}</div>
                                    <div class="flex flex-col gap-y-2 text-neutral-600 dark:text-neutral-400">
                                        <div class="flex items-center gap-x-2 text-xs uppercase text-neutral-500">
                                            <div class="w-2 h-2 {{ $omv_lines_recent > 0 ? "bg-green-500" : "bg-red-500" }} rounded-full"></div>
                                            <div class="">{{ $omv_lines_recent > 0 ? $omv_lines_recent . " " . __("line ") : __("luring") }}</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="px-6 py-3 text-lg">
                                    <i class="icon-chevron-right"></i>
                                </div>
                            </div>
                        </a>
                        <a href="{{ route('insights.ctc.index') }}" class="block hover:bg-caldy-500 hover:bg-opacity-10" wire:navigate>
                            <div class="flex items-center">
                                <div class="px-6 py-3">
                                    <img src="/ink-rtc.svg" class="w-16 h-16 dark:invert" />
                                </div>
                                <div class="grow">
                                    <div class="text-lg font-medium text-neutral-900 dark:text-neutral-100">{{ __("Kendali tebal calendar") }}</div>
                                    <div class="flex flex-col gap-y-2 text-neutral-600 dark:text-neutral-400">
                                        <div class="flex items-center gap-x-2 text-xs uppercase text-neutral-500">
                                            <div class="w-2 h-2 {{ $ctc_lines_recent > 0 ? "bg-green-500" : "bg-red-500" }} rounded-full"></div>
                                            <div class="">{{ $ctc_lines_recent > 0 ? $ctc_lines_recent . " " . __("line ") : __("luring") }}</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="px-6 py-3 text-lg">
                                    <i class="icon-chevron-right"></i>
                                </div>
                            </div>
                        </a>
                        <a href="{{ route('insights.rdc.index') }}" class="block hover:bg-caldy-500 hover:bg-opacity-10" wire:navigate>
                            <div class="flex items-center">
                                <div class="px-6 py-3">
                                    <img src="/ink-rdc.svg" class="w-16 h-16 dark:invert" />
                                </div>
                                <div class="grow">
                                    <div class="text-lg font-medium text-neutral-900 dark:text-neutral-100">{{ __("Sistem data rheometer") }}</div>
                                    <div class="flex flex-col gap-y-2 text-neutral-600 dark:text-neutral-400">
                                        <div class="flex items-center gap-x-2 text-xs uppercase text-neutral-500">
                                            <div class="w-2 h-2 {{ $rdc_machines_recent > 0 ? "bg-green-500" : "bg-red-500" }} rounded-full"></div>
                                            <div class="">{{ $rdc_machines_recent > 0 ? $rdc_machines_recent . " " . __("mesin ") : __("luring") }}</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="px-6 py-3 text-lg">
                                    <i class="icon-chevron-right"></i>
                                </div>
                            </div>
                        </a>
                    </div>                
                </div>
                <div>
                    <h1 class="uppercase text-sm text-neutral-500 mb-4 px-8">{{ __("Sistem area assembly") }}</h1>
                    <div class="bg-white dark:bg-neutral-800 shadow overflow-hidden sm:rounded-lg divide-y divide-neutral-200 dark:text-white dark:divide-neutral-700">
                        <a href="{{ route('insights.dwp.index') }}" class="block hover:bg-caldy-500 hover:bg-opacity-10" wire:navigate>
                            <div class="flex items-center">
                                <div class="px-6 py-3">
                                    <img src="/ink-dwp.svg" class="w-16 h-16 dark:invert" />
                                </div>
                                <div class="grow">
                                    <div class="text-lg font-medium text-neutral-900 dark:text-neutral-100">{{ __("Pemantauan deep well press") }}</div>
                                    <div class="flex flex-col gap-y-2 text-neutral-600 dark:text-neutral-400">
                                        <div class="flex items-center gap-x-2 text-xs uppercase text-neutral-500">
                                            <div class="w-2 h-2 {{ $dwp_lines_recent > 0 ? 'bg-green-500' : 'bg-red-500' }} rounded-full"></div>
                                            <div class="">{{ $dwp_lines_recent > 0 ? $dwp_lines_recent . " " . __("line ") : __("luring") }}</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="px-6 py-3 text-lg">
                                    <i class="icon-chevron-right"></i>
                                </div>
                            </div>
                        </a>

                        <a href="{{ route('insights.bpm.index') }}" class="block hover:bg-caldy-500 hover:bg-opacity-10" wire:navigate>
                            <div class="flex items-center">
                                <div class="px-6 py-3">
                                    <img src="/bpm.png" class="w-16 h-16 dark:invert fs-5" />
                                </div>
                                <div class="grow">
                                    <div class="text-lg font-medium text-neutral-900 dark:text-neutral-100">{{ __("Pemantauan Emergency Bpm") }}</div>
                                    <div class="flex flex-col gap-y-2 text-neutral-600 dark:text-neutral-400">
                                        <div class="flex items-center gap-x-2 text-xs uppercase text-neutral-500">
                                            <div class="w-2 h-2 {{ $bpm_lines_recent > 0 ? 'bg-green-500' : 'bg-red-500' }} rounded-full"></div>
                                            <div class="">{{ $bpm_lines_recent > 0 ? $bpm_lines_recent . " " . __("line ") : __("luring") }}</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="px-6 py-3 text-lg">
                                    <i class="icon-chevron-right"></i>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
            <div class="flex flex-col gap-6">
                <div>
                    <h1 class="uppercase text-sm text-neutral-500 mb-4 px-8">{{ __("Sistem Area IP") }}</h1>
                    <div class="bg-white dark:bg-neutral-800 shadow overflow-hidden sm:rounded-lg divide-y divide-neutral-200 dark:text-white dark:divide-neutral-700">
                        <a href="{{ route('insights.clm.index') }}" class="block hover:bg-caldy-500 hover:bg-opacity-10" wire:navigate>
                            <div class="flex items-center">
                                <div class="grow px-6 py-3 flex gap-x-6 items-center">
                                    <div>
                                        {{ __("Gedung IP") }}
                                    </div>
                                    <div class="grow flex gap-x-2 items-stretch text-sm text-neutral-600 dark:text-neutral-400">
                                        @if ($climate_data_stale)
                                            <div class="text-yellow-500 mr-1" title="{{ __("Data lebih dari 3 jam yang lalu") }}">
                                                <i class="icon-triangle-alert"></i>
                                            </div>
                                        @endif

                                        <div>
                                            <i class="icon icon-thermometer"></i>
                                            <span>{{ $temperature_latest !== null ? number_format($temperature_latest, 1) : "--.-" }}</span>
                                            <span>°C</span>
                                        </div>
                                        <div class="w-px bg-neutral-200 dark:bg-neutral-700"></div>
                                        <div>
                                            <i class="icon icon-droplet"></i>
                                            <span>{{ $humidity_latest !== null ? number_format($humidity_latest, 1) : "--.-" }}</span>
                                            <span>%</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="px-6 py-3 text-lg">
                                    <i class="icon-chevron-right"></i>
                                </div>
                            </div>
                        </a>
                        <a href="{{ route('insights.stc.index') }}" class="block hover:bg-caldy-500 hover:bg-opacity-10" wire:navigate>
                            <div class="flex items-center">
                                <div class="px-6 py-3">
                                    <img src="/ink-stc.svg" class="w-16 h-16 dark:invert" />
                                </div>
                                <div class="grow">
                                    <div class="text-lg font-medium text-neutral-900 dark:text-neutral-100">{{ __("Kendali chamber IP") }}</div>
                                    <div class="flex flex-col gap-y-2 text-neutral-600 dark:text-neutral-400">
                                        <div class="flex items-center gap-x-2 text-xs uppercase text-neutral-500">
                                            <div class="w-2 h-2 {{ $stc_machines_count > 0 ? "bg-green-500" : "bg-red-500" }} rounded-full"></div>
                                            <div>{{ $stc_machines_count > 0 ? $stc_machines_count . " " . __("line ") : __("luring") }}</div>
                                            <div>•</div>
                                            <div>{{ __("Data HB") . ": " . $stc_d_sums_recent . " " . __("line ") }}</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="px-6 py-3 text-lg">
                                    <i class="icon-chevron-right"></i>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
                <div>
                    <h1 class="uppercase text-sm text-neutral-500 mb-4 px-8">{{ __("Sistem Area OKC") }}</h1>
                    <div class="bg-white dark:bg-neutral-800 shadow overflow-hidden sm:rounded-lg divide-y divide-neutral-200 dark:text-white dark:divide-neutral-700">
                        <a href="{{ route('insights.ldc.index') }}" class="block hover:bg-caldy-500 hover:bg-opacity-10" wire:navigate>
                            <div class="flex items-center">
                                <div class="px-6 py-3">
                                    <img src="/ink-ldc.svg" class="w-16 h-16 dark:invert" />
                                </div>
                                <div class="grow">
                                    <div class="text-lg font-medium text-neutral-900 dark:text-neutral-100">{{ __("Sistem data kulit") }}</div>
                                    <div class="flex flex-col gap-y-2 text-neutral-600 dark:text-neutral-400">
                                        <div class="flex items-center gap-x-2 text-xs uppercase text-neutral-500">
                                            <div class="w-2 h-2 {{ $ldc_codes_recent > 0 ? 'bg-green-500' : 'bg-red-500' }} rounded-full"></div>
                                            <div>{{ $ldc_codes_recent > 0 ? $ldc_codes_recent . " " . __("mesin ") : __("luring") }}</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="px-6 py-3 text-lg">
                                    <i class="icon-chevron-right"></i>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
