<x-app-layout>
    <x-slot name="title">{{ __("Admin") }}</x-slot>

    <div class="py-12 text-neutral-800 dark:text-neutral-200">
        <div class="max-w-xl flex flex-col gap-y-6 mx-auto sm:px-6 lg:px-8">
            <div class="relative text-neutral h-32 sm:rounded-lg overflow-hidden mb-8 border border-dashed border-neutral-300 dark:border-neutral-500">
                <div class="absolute top-0 left-0 flex h-full items-center px-4 lg:px-8 text-neutral-500">
                    <div>
                        <div class="uppercase font-bold mb-2">
                            <i class="icon-triangle-alert me-2"></i>
                            {{ __("Peringatan") }}
                        </div>
                        <div>{{ __("Kamu sedang mengakses halaman yang hanya diperuntukkan bagi superuser.") }}</div>
                    </div>
                </div>
            </div>
            <div>
                <h1 class="uppercase text-sm text-neutral-500 mb-4 px-8">{{ __("Akun") }}</h1>
                <div class="bg-white dark:bg-neutral-800 shadow overflow-hidden sm:rounded-lg divide-y divide-neutral-200 dark:text-white dark:divide-neutral-700">
                    <a href="{{ route("admin.account-manage") }}" class="block hover:bg-caldy-500 hover:bg-opacity-10" wire:navigate>
                        <div class="flex items-center px-6 py-5">
                            <div class="grow">
                                <div class="text-lg font-medium text-neutral-900 dark:text-neutral-100">{{ __("Kelola akun") }}</div>
                                <div class="text-sm text-neutral-500">
                                    {{ __("Edit, nonaktifkan, atur ulang kata sandi") }}
                                </div>
                            </div>
                            <div class="text-lg">
                                <i class="icon-chevron-right"></i>
                            </div>
                        </div>
                    </a>
                </div>
            </div>
            <div>
                <h1 class="uppercase text-sm text-neutral-500 mb-4 px-8">{{ __("Sistem") }}</h1>
                <div class="bg-white dark:bg-neutral-800 shadow overflow-hidden sm:rounded-lg divide-y divide-neutral-200 dark:text-white dark:divide-neutral-700">
                    <a href="{{ route("admin.daemon-manage") }}" class="block hover:bg-caldy-500 hover:bg-opacity-10" wire:navigate>
                        <div class="flex items-center px-6 py-5">
                            <div class="grow">
                                <div class="text-lg font-medium text-neutral-900 dark:text-neutral-100">{{ __("Kelola daemon") }}</div>
                                <div class="text-sm text-neutral-500">
                                    {{ __("Jalankan, hentikan, monitor daemon artisan") }}
                                </div>
                            </div>
                            <div class="text-lg">
                                <i class="icon-chevron-right"></i>
                            </div>
                        </div>
                    </a>

                    <a href="{{ route('uptime.monitor') }}" class="block hover:bg-caldy-500 hover:bg-opacity-10" wire:navigate>
                        <div class="flex items-center px-6 py-5">
                            <div class="grow">
                                <div class="text-lg font-medium text-neutral-900 dark:text-neutral-100">{{ __("Uptime Monitoring") }}</div>
                                <div class="text-sm text-neutral-500">
                                    {{ __("Monitoring uptime semua layanan") }}
                                </div>
                            </div>
                            <div class="text-lg">
                                <i class="icon-chevron-right"></i>
                            </div>
                        </div>
                    </a>
                </div>
            </div>
        </div>
        <div class="mt-10 text-center">
            <x-link
                class="text-sm uppercase font-medium leading-5 text-neutral-500 dark:text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-300"
                href="/doc/index.html"
                target="_blank"
            >
                <i class="icon-book-open me-2"></i>{{ __("Dokumentasi") }}
            </x-link>
        </div>
    </div>
</x-app-layout>
