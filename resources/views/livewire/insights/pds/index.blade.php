<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;

new #[Layout("layouts.app")] class extends Component {
    //
};

?>

<x-slot name="title">{{ __("Pemantauan dosing PH") }}</x-slot>

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-neutral-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-neutral-900 dark:text-neutral-100">
                <div class="mb-6">
                    <h2 class="text-2xl font-semibold">{{ __("Pemantauan dossing PH") }}</h2>
                    <p class="text-neutral-600 dark:text-neutral-400 mt-2">
                        {{ __("Sistem monitoring dossing PH untuk proses assembly") }}
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-neutral-50 dark:bg-neutral-700 p-6 rounded-lg">
                        <h3 class="text-lg font-medium mb-4">{{ __("Data & Analisis") }}</h3>
                        <p class="text-neutral-600 dark:text-neutral-400 mb-4">
                            {{ __("Akses data real-time dan ringkasan historis dari semua line PDS") }}
                        </p>
                        <a href="{{ route('insights.pds.data.index') }}" 
                           class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-neutral-800 transition ease-in-out duration-150"
                           wire:navigate>
                            {{ __("Lihat Data") }}
                        </a>
                    </div>

                    @can('manage-devices', App\Models\InsDwpAuth::class)
                    <div class="bg-neutral-50 dark:bg-neutral-700 p-6 rounded-lg">
                        <h3 class="text-lg font-medium mb-4">{{ __("Kelola Perangkat") }}</h3>
                        <p class="text-neutral-600 dark:text-neutral-400 mb-4">
                            {{ __("Konfigurasi perangkat PH Dosing dan pengaturan line production") }}
                        </p>
                        <a href="{{ route('insights.pds.manage.devices') }}" 
                           class="inline-flex items-center px-4 py-2 bg-neutral-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-neutral-700 focus:bg-neutral-700 active:bg-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-500 focus:ring-offset-2 dark:focus:ring-offset-neutral-800 transition ease-in-out duration-150"
                           wire:navigate>
                            {{ __("Kelola") }}
                        </a>
                    </div>
                    @endcan
                </div>
            </div>
        </div>
    </div>
</div>