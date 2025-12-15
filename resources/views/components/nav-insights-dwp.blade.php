<header class="bg-white dark:bg-neutral-800 shadow">
    <div class="flex justify-between max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div>  
            <h2 class="flex items-center gap-x-4 font-semibold text-xl text-neutral-800 dark:text-neutral-200 leading-tight">
                <x-link href="{{ route('insights') }}" class="inline-block px-3 py-6" wire:navigate>
                    <i class="icon-arrow-left"></i>
                </x-link>
                <div>
                    <span class="hidden sm:inline">{{ __('Pemantauan deep well press') }}</span>
                    <span class="sm:hidden inline">{{ __('DWP') }}</span>
                </div>
            </h2>
        </div>
        <div class="space-x-8 -my-px ml-10 flex">
            <x-nav-link class="text-sm px-1 uppercase" href="/insights/dwp/data" :active="request()->routeIs('insights.dwp.data.index') && !request()->query('view')" wire:navigate>
                <i class="icon-layout-dashboard text-sm"></i><span class="ms-3 hidden lg:inline">{{ __('Dashboard') }}</span>
            </x-nav-link>
            <x-nav-link class="text-sm px-1 uppercase" href="/insights/dwp/data?view=pressure" :active="request()->routeIs('insights.dwp.data.index') && request()->query('view') === 'pressure'" wire:navigate>
                <i class="icon-circle-gauge text-sm"></i><span class="ms-3 hidden lg:inline">{{ __('Pressure') }}</span>
            </x-nav-link>
            <x-nav-link class="text-sm px-1 uppercase" href="/insights/dwp/data?view=time-alarm" :active="request()->routeIs('insights.dwp.data.index') && request()->query('view') === 'time-alarm'" wire:navigate>
                <i class="icon-alarm-clock text-sm"></i><span class="ms-3 hidden lg:inline">{{ __('Alarm Constraint') }}</span>
            </x-nav-link>
            <x-nav-link class="text-sm px-1 uppercase" href="/insights/dwp/data?view=loadcell" :active="request()->routeIs('insights.dwp.data.index') && in_array(request()->query('view'), ['loadcell', 'raw-loadcell'])" wire:navigate>
                <i class="icon-circle-gauge text-sm"></i><span class="ms-3 hidden lg:inline">{{ __('Loadcell') }}</span>
            </x-nav-link>
            <x-nav-link class="text-sm px-1 uppercase" href="{{ route('insights.dwp.manage.index') }}" :active="request()->routeIs('insights.dwp.manage.*')" wire:navigate>
                <i class="icon-settings text-sm"></i><span class="ms-3 hidden lg:inline">{{ __('Kelola') }}</span>
            </x-nav-link>
        </div>
    </div>
</header>