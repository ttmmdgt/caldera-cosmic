<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;

new #[Layout("layouts.app")] class extends Component {
    #[Url]
    public $view = "dashboard";

    public array $view_titles = [];
    public array $view_icons = [];

    public function mount()
    {
        $this->view_titles = [
            "dashboard" => __("Dashboard"),
            "time-alarm" => __("DWP Time Constraint Alarm"),
            "pressure" => __("DWP Pressure"),
        ];

        $this->view_icons = [
            "dashboard" => "icon-layout-dashboard",
            "time-alarm" => "icon-alarm-clock",
            "pressure" => "icon-database",
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

    #[On('update-menu')]
    public function updateMenu($view){
        // Condition Menu
        if ($view === "dashboard"){
             $this->view_titles = [
                "dashboard" => __("Dashboard"),
            ];

            $this->view_icons = [
                "dashboard" => "icon-layout-dashboard",
            ];
        }

        if ($view === "time-alarm" || $view === "summary-time-alarm"){
            $this->view_titles = [
                "time-alarm" => __("Raw Data Time Constraint Alarm"),
                "summary-time-alarm" => __("Sumarry Time Alarm"),
            ];

            $this->view_icons = [
                "time-alarm" => "icon-alarm-clock",
                "summary-time-alarm" => "icon-notebook-text",
            ];
        }

        if ($view === "raw" || $view === "summary" || $view === "pressure" || $view === "uptime-monitoring"){
            $this->view_titles = [
                "pressure" => __("Machine Performance"),
                "raw" => __("Raw Data"),
                "summary" => __("Summary DWP Pressure"),
                "uptime-monitoring" => __("Uptime Monitoring"),
            ];

            $this->view_icons = [
                "pressure" => "icon-database",
                "raw" => "icon-database",
                "summary" => "icon-notebook-text",
                "uptime-monitoring" => "icon-activity",
            ];
        }

        if ($view === "loadcell" || $view === "raw-loadcell" || $view === "summary-loadcell"){
            $this->view_titles = [
                "loadcell" => __("DWP Loadcell"),
                "raw-loadcell" => __("Raw Data"),
                "summary-loadcell" => __("Summary Loadcell"),
            ];

            $this->view_icons = [
                "loadcell" => "icon-circle-gauge",
                "raw-loadcell" => "icon-database",
                "summary-loadcell" => "icon-notebook-text",
            ];
        }
    }
};

?>

<x-slot name="title">{{ __("Data - Pemantauan deep well press") }}</x-slot>

<x-slot name="header">
    <x-nav-insights-dwp></x-nav-insights-dwp>
</x-slot>

<div id="content" class="py-12 max-w-7xl mx-auto sm:px-6 lg:px-8 text-neutral-800 dark:text-neutral-200 gap-4">
    <div wire:key="dwp-data-nav" class="flex mb-6">
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
            </x-slot>
        </x-dropdown>
    </div>

    <div wire:loading.class.remove="hidden" class="hidden h-96">
        <x-spinner></x-spinner>
    </div>

    <div wire:key="dwp-data-container" wire:loading.class="hidden">
        @switch($view)
            @case("pressure")
                <livewire:insights.dwp.data.pressure />
                @break
            @case("raw")
                <livewire:insights.dwp.data.raw />
                @break
            @case("dashboard")
                <livewire:insights.dwp.data.dashboard />
                @break
            @case("summary")
                <livewire:insights.dwp.data.summary />
                @break
            @case("time-alarm")
                <livewire:insights.dwp.data.time-alarm />
                @break
            @case("summary-time-alarm")
                <livewire:insights.dwp.data.summary-time-alarm />
                @break
            @case("loadcell")
                <livewire:insights.dwp.data.loadcell />
                @break
            @case("summary-loadcell")
                <livewire:insights.dwp.data.summary-loadcell />
                @break
            @case("raw-loadcell")
                <livewire:insights.dwp.data.raw-loadcell />
                @break
            @case("uptime-monitoring")
                <livewire:insights.dwp.data.uptime-monitoring />
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
</div>