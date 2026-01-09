<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;

use Carbon\Carbon;
use Illuminate\Support\Facades\Response;

new #[Layout("layouts.app")] class extends Component {
    #[Url]
    public $view = "summary";
    public array $view_titles = [];
    public array $view_icons = [];

    public function mount()
    {
        $this->view_titles = [
            "summary" => __("Ringkasan"),
            // 'summary-operational'   => __('Ringkasan operasional'),
            "readings" => __("Pembacaan"),
            "adjustments" => __("Penyetelan"),
            "history" => __("Riwayat"),
            "comparison" => __("Perbandingan"),
            "machine-performance" => __("Performa mesin"),
            "deviation-tracking" => __("Pelacakan deviasi"),
            "deviation-duration-tracking" => __("Pelacakan durasi deviasi"),
            "target-variance" => __("Varians target"),
            // 'zone-analysis'         => __('Analisis zona'),
            // 'trend-analysis'        => __('Analisis tren'),
            // 'operator-performance'  => __('Performa operator'),
        ];

        $this->view_icons = [
            "summary" => "icon-layout-grid",
            // 'summary-operational'   => 'icon-settings',
            "readings" => "icon-credit-card",
            "adjustments" => "icon-settings-2",
            "history" => "icon-clock",
            "comparison" => "icon-git-compare",
            "machine-performance" => "icon-zap",
            "deviation-tracking" => "icon-scan-search",
            "deviation-duration-tracking" => "icon-timer",
            "target-variance" => "icon-focus",
        ];
    }

    public function getViewTitle(): string
    {
        return $this->view_titles[$this->view] ?? "";
    }

    public function getViewIcon(): string
    {
        return $this->view_icons[$this->view] ?? "";
    }
};

?>

<x-slot name="title">{{ __("Kendali chamber IP") }}</x-slot>

<x-slot name="header">
    <link href="/print-landscape.css" type="text/css" rel="stylesheet" media="print" />
    <x-nav-insights-stc></x-nav-insights-stc>
</x-slot>

<x-slot name="printable">
    <livewire:insights.stc.data.d-sum-print />
</x-slot>

<div id="content" class="relative py-12 max-w-7xl mx-auto sm:px-6 lg:px-8 text-neutral-800 dark:text-neutral-200">
    <div wire:key="stc-data-index-nav" class="flex px-8 mb-6">
        <x-dropdown align="left" width="60">
            <x-slot name="trigger">
                <x-text-button type="button" class="flex gap-2 items-center ml-1">
                    <i class="{{ $this->getViewIcon() }}"></i>
                    <div class="text-2xl">{{ $this->getViewTitle() }}</div>
                    <i class="icon-chevron-down"></i>
                </x-text-button>
            </x-slot>
            <x-slot name="content">
                @foreach ($view_titles as $view_key => $view_title)
                    <x-dropdown-link href="#" wire:click.prevent="$set('view', '{{ $view_key }}')" class="flex items-center gap-2">
                        <i class="{{ $view_icons[$view_key] }}"></i>
                        <span>{{ $view_title }}</span>
                        @if ($view === $view_key)
                            <div class="ml-auto w-2 h-2 bg-caldy-500 rounded-full"></div>
                        @endif
                    </x-dropdown-link>
                @endforeach

                {{-- <hr class="border-neutral-300 dark:border-neutral-600" /> --}}
            </x-slot>
        </x-dropdown>
    </div>
    <div wire:loading.class.remove="hidden" class="hidden h-96">
        <x-spinner></x-spinner>
    </div>
    <div wire:key="stc-data-index-container" wire:loading.class="hidden">
        @switch($view)
            @case("summary")
                <livewire:insights.stc.data.summary />

                @break
            @case("summary-operational")
                <livewire:insights.stc.data.summary-operational />

                @break
            @case("readings")
                <livewire:insights.stc.data.readings />

                @break
            @case("adjustments")
                <livewire:insights.stc.data.adjustments />

                @break
            @case("history")
                <livewire:insights.stc.data.history />

                @break
            @case("comparison")
                <livewire:insights.stc.data.comparison />

                @break
            @case("deviation-tracking")
                <livewire:insights.stc.data.deviation-tracking />

                @break
            @case("deviation-duration-tracking")
                <livewire:insights.stc.data.deviation-duration-tracking />

                @break
            @case("machine-performance")
                <livewire:insights.stc.data.machine-performance />

                @break
            @case("target-variance")
                <livewire:insights.stc.data.target-variance />

                @break
            @case("zone-analysis")
                <livewire:insights.stc.data.zone-analysis />

                @break
            @case("trend-analysis")
                <livewire:insights.stc.data.trend-analysis />

                @break
            @case("operator-performance")
                <livewire:insights.stc.data.operator-performance />

                @break
            @default
                <div wire:key="no-view" class="w-full py-20">
                    <div class="text-center text-neutral-300 dark:text-neutral-700 text-5xl mb-3">
                        <i class="icon-tv-minimal relative"><i class="icon-circle-help absolute bottom-0 -right-1 text-lg text-neutral-500 dark:text-neutral-400"></i></i>
                    </div>
                    <div class="text-center text-neutral-400 dark:text-neutral-600">{{ __("Pilih tampilan") }}</div>
                </div>
        @endswitch
    </div>
    <script>
        function progressApp() {
            return {
                observeProgress() {
                    const streamElement = document.querySelector('[wire\\:stream="progress"]');

                    if (streamElement) {
                        const observer = new MutationObserver((mutations) => {
                            mutations.forEach((mutation) => {
                                if (mutation.type === 'characterData' || mutation.type === 'childList') {
                                    const currentValue = streamElement.textContent;
                                    console.log('Stream value updated:', currentValue);

                                    // Do something with the captured value
                                    this.handleProgress(currentValue);
                                }
                            });
                        });

                        observer.observe(streamElement, {
                            characterData: true,
                            childList: true,
                            subtree: true,
                        });
                    }
                },

                handleProgress(value) {
                    this.progress = value;
                },
            };
        }
    </script>
</div>
