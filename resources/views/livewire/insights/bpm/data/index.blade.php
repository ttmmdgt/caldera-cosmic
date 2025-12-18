<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;

new #[Layout("layouts.app")] class extends Component {
    #[Url]
    public $view = "raw";

    public array $view_titles = [];
    public array $view_icons = [];

    public function mount()
    {
        $this->view_titles = [
            "raw-data" => __("Raw Data BPM"),
            "summary" => __("Summary BPM"),
        ];

        $this->view_icons = [
            "raw-data" => "icon-alarm-clock",
            "summary" => "icon-layout-dashboard",
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
        if ($view === "raw" || $view === "summary" || $view === "summary-line") {
            $this->view_titles = [
                "raw" => __("Raw Data"),
                "summary" => __("Summary Emergency (Per Plant)"),
                "summary-line" => __("Summary Emergency (Per Line)"),
            ];

            $this->view_icons = [
                "raw" => "icon-database",
                "summary" => "icon-notebook-text",
                "summary-line" => "icon-chart-line",
            ];
        }
    }
};

?>

<x-slot name="title">{{ __("Data - Pemantauan deep well press") }}</x-slot>

<x-slot name="header">
    <x-nav-insights-bpm></x-nav-insights-bpm>
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

    <div wire:key="bpm-data-container" wire:loading.class="hidden">
        @switch($view)
            @case("summary")
                <livewire:insights.bpm.data.summary-bpm />
                @break
            @case("raw")
                <livewire:insights.bpm.data.raw />
                @break
            @case("summary-line")
                <livewire:insights.bpm.data.summary-line />
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