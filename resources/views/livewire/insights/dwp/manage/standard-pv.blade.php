<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use App\Models\InsDwpDevice;
use App\Models\InsDwpStandardPV;


new #[Layout("layouts.app")] class extends Component {
    public function with(): array
    {
        return [
            'standards' => InsDwpStandardPV::orderBy('setting_name')->get(),
        ];
    }
}; ?>

<x-slot name="title">{{ __("Standar PV") . " — " . __("Pemantauan deep well press") }}</x-slot>
<x-slot name="header">
    <x-nav-insights-dwp-sub />
</x-slot>
<div id="content" class="py-12 max-w-4xl mx-auto sm:px-3 text-neutral-800 dark:text-neutral-200">
    <div>

        <!-- Flash Messages -->
        @if (session()->has('message'))
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded mb-4">
                {{ session('message') }}
            </div>
        @endif

        @if (session()->has('error'))
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4">
                {{ session('error') }}
            </div>
        @endif

        @error('permission')
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4">
                {{ $message }}
            </div>
        @enderror

        <div class="flex flex-col sm:flex-row gap-y-6 justify-between px-6">
            <h1 class="text-2xl text-neutral-900 dark:text-neutral-100">{{ __("Standar PV Mesin") }}</h1>
            <div class="flex justify-end gap-x-2">
                @can("manage", InsDwpStandardPV::class)
                    <x-secondary-button type="button" x-on:click.prevent="$dispatch('open-modal', 'standard-create')">
                        <i class="icon-plus"></i> {{ __("Tambah Standar") }}
                    </x-secondary-button>
                @endcan
            </div>
        </div>
        
        <div class="overflow-auto w-full my-8">
            <div class="p-0 sm:p-1">
                <div class="bg-white dark:bg-neutral-800 shadow table sm:rounded-lg">
                    <table wire:key="standards-table" class="table">
                        <thead>
                            <tr>
                                <th>{{ __("ID") }}</th>
                                <th>{{ __("Nama Mesin") }}</th>
                                <th>{{ __("Side Min") }}</th>
                                <th>{{ __("Side Max") }}</th>
                                <th>{{ __("Toe/Heel Min") }}</th>
                                <th>{{ __("Toe/Heel Max") }}</th>
                                <th>{{ __("Aksi") }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($standards as $standard)
                                <tr
                                    wire:key="standard-tr-{{ $standard->id }}"
                                    class="hover:bg-neutral-50 dark:hover:bg-neutral-700 transition-colors"
                                >
                                    <td class="text-center">
                                        {{ $standard->id }}
                                    </td>
                                    <td class="font-medium">
                                        {{ $standard->setting_name }}
                                    </td>
                                    <td class="text-center">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                            @if(is_array($standard->setting_value) && isset($standard->setting_value['setting_std']))
                                                {{ $standard->setting_value['setting_std']['min_s'] ?? '-' }}
                                            @else
                                                {{ $standard->min ?? '-' }}
                                            @endif
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200">
                                            @if(is_array($standard->setting_value) && isset($standard->setting_value['setting_std']))
                                                {{ $standard->setting_value['setting_std']['max_s'] ?? '-' }}
                                            @else
                                                {{ $standard->max ?? '-' }}
                                            @endif
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                            @if(is_array($standard->setting_value) && isset($standard->setting_value['setting_std']))
                                                {{ $standard->setting_value['setting_std']['min_th'] ?? '-' }}
                                            @else
                                                {{ $standard->min ?? '-' }}
                                            @endif
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">
                                            @if(is_array($standard->setting_value) && isset($standard->setting_value['setting_std']))
                                                {{ $standard->setting_value['setting_std']['max_th'] ?? '-' }}
                                            @else
                                                {{ $standard->max ?? '-' }}
                                            @endif
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        @can("manage", InsDwpStandardPV::class)
                                            <button 
                                                type="button"
                                                class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                                x-on:click="
                                                    $dispatch('open-modal', 'standard-edit');
                                                    $dispatch('standard-edit', { id: {{ $standard->id }} })
                                                "
                                            >
                                                <i class="icon-pencil"></i>
                                            </button>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-12 text-neutral-500">
                                        {{ __("Belum ada standar PV. Klik tombol 'Tambah Standar' untuk membuat yang baru.") }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div wire:key="standard-create">
            <x-modal name="standard-create" maxWidth="xl">
                <livewire:insights.dwp.manage.standard-create />
            </x-modal>
        </div>
        <div wire:key="standard-edit">
            <x-modal name="standard-edit" maxWidth="xl">
                <livewire:insights.dwp.manage.standard-edit />
            </x-modal>
        </div>
    </div>
</div>
