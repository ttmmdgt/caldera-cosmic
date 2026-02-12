<?php

use App\Models\InsPhDosingDevice;
use App\Models\InsPhDosingAuth;
use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Rule;
use Livewire\Attributes\On;

new #[Layout("layouts.app")] class extends Component {
    public $devices;

    public function mount()
    {
        $this->loadDevices();
    }


    #[On("updated")]
    public function loadDevices()
    {
        $this->devices = InsPhDosingDevice::orderBy('name')->get();
    }

};

?>

<x-slot name="title">{{ __("Perangkat") . " — " . __("Pemantauan PDS") }}</x-slot>
<x-slot name="header">
    <x-nav-insights-pds-sub />
</x-slot>
<div id="content" class="py-12 max-w-2xl mx-auto sm:px-3 text-neutral-800 dark:text-neutral-200">
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
            <h1 class="text-2xl text-neutral-900 dark:text-neutral-100">{{ __("Perangkat") }}</h1>
            <div class="flex justify-end gap-x-2">
                @can("manage", InsPhDosingDevice::class)
                    <x-secondary-button type="button" x-on:click.prevent="$dispatch('open-modal', 'device-create')"><i class="icon-plus"></i></x-secondary-button>
                @endcan
            </div>
        </div>
        
        <div class="overflow-auto w-full my-8">
            <div class="p-0 sm:p-1">
                <div class="bg-white dark:bg-neutral-800 shadow table sm:rounded-lg">
                    <table wire:key="devices-table" class="table">
                        <tr>
                            <th>{{ __("ID") }}</th>
                            <th>{{ __("Nama") }}</th>
                            <th>{{ __("Plant") }}</th>
                            <th>{{ __("IP Address") }}</th>
                            <th>{{ __("Status") }}</th>
                        </tr>
                        @foreach ($devices as $device)
                            <tr
                                wire:key="device-tr-{{ $device->id . $loop->index }}"
                                tabindex="0"
                                x-on:click="
                                    $dispatch('open-modal', 'device-edit')
                                    $dispatch('device-edit', { id: {{ $device->id }} })
                                "
                            >
                                <td>
                                    {{ $device->id }}
                                </td>
                                <td>
                                    {{ $device->name }}
                                </td>
                                <td>
                                    {{ $device->plant }}
                                </td>
                                <td>
                                    {{ $device->ip_address }}
                                </td>
                                <td>
                                    @if($device->is_active)
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                            {{ __("Aktif") }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                                            {{ __("Nonaktif") }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                    <div wire:key="devices-none">
                        @if (!$devices->count())
                            <div class="text-center py-12">
                                {{ __("Tak ada perangkat ditemukan") }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div wire:key="device-create-modal-wrapper">
            <x-modal name="device-create" maxWidth="xl">
                <livewire:insights.pds.manage.device-create wire:key="pds-device-create-modal" lazy />
            </x-modal>
        </div>
        <div wire:key="device-edit-modal-wrapper">
            <x-modal name="device-edit" maxWidth="xl">
                <livewire:insights.pds.manage.device-edit wire:key="pds-device-edit-modal" lazy />
            </x-modal>
        </div>
    </div>
</div>
