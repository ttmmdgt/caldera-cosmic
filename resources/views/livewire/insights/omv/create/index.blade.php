<?php
use Livewire\Volt\Component;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Layout;

new #[Layout("layouts.app")] class extends Component {
    public string $userq = "";
    public int $user_id = 0;

    #[Renderless]
    public function updatedUserq()
    {
        $this->dispatch("userq-updated", $this->userq);
    }
};

?>

<x-slot name="title">{{ __("Pemantauan open mill") }}</x-slot>

<x-slot name="header">
    <x-nav-insights-omv></x-nav-insights-omv>
</x-slot>

<div id="content" class="pt-8 pb-3 max-w-7xl mx-auto sm:px-6 lg:px-8 text-neutral-800 dark:text-neutral-200">
    @if (! Auth::user())
        <div class="flex flex-col items-center gap-y-6 px-6 py-20">
            <div class="text-center text-neutral-300 dark:text-neutral-700 text-5xl">
                <i class="icon-circle-alert"></i>
            </div>
            <div class="text-center text-neutral-500 dark:text-neutral-600">
                {{ __("Masuk terlebih dahulu untuk menggunakan timer OMV") }}
            </div>
            <div>
                <a
                    href="{{ route("login", ["redirect" => url()->current()]) }}"
                    wire:navigate
                    class="flex items-center px-6 py-3 mb-3 text-white bg-caldy-600 rounded-md sm:mb-0 hover:bg-caldy-700 sm:w-auto"
                >
                    {{ __("Masuk") }}
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="w-5 h-5 ml-1"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </a>
            </div>
        </div>
    @else
        <div x-data="{ ...app(), userq: @entangle("userq").live }" x-init="
            loadRecipes()
            fetchLine()
        ">
            <div x-show="statsVisible" x-cloak @keydown.window.ctrl.d.prevent="statsVisible = !statsVisible" class="flex gap-2 px-4 mb-4">
                <div class="text-sm">{{ __("Statistik") . ": " }}</div>
                <x-pill color="yellow">
                    <span>evalTolerance:</span>
                    <span class="font-mono" x-text="evalTolerance"></span>
                </x-pill>
                <x-pill color="yellow">
                    <span>evalFalseLimit:</span>
                    <span class="font-mono" x-text="evalFalseLimit"></span>
                </x-pill>
                <x-pill color="yellow">
                    <span>timerElapsedSeconds:</span>
                    <span class="font-mono" x-text="timerElapsedSeconds.toFixed(1)"></span>
                </x-pill>
                <x-pill color="yellow">
                    <span>timerEvalFalseCount:</span>
                    <span class="font-mono" x-text="timerEvalFalseCount"></span>
                </x-pill>
                <x-pill color="yellow">
                    <span>timerOvertimeElapsed:</span>
                    <span class="font-mono" x-text="timerOvertimeElapsed"></span>
                </x-pill>
                <x-pill color="yellow">
                    <span>batchAmp:</span>
                    <span class="font-mono" x-text="batchAmps[batchAmps.length - 1]?.value ?? 0"></span>
                </x-pill>
                <x-pill color="yellow" @click="$dispatch('open-modal', 'threshold-amp-adjust')">
                    <span>thresholdAmp:</span>
                    <span class="font-mono" x-text="thresholdAmp"></span>
                </x-pill>
                <x-modal name="threshold-amp-adjust">
                    <div class="p-6">
                        <label for="threshold-amp" class="block px-3 mb-2 uppercase text-xs text-neutral-500">Ampere threshold</label>
                        <x-text-input id="threshold-amp" x-model="thresholdAmp" type="number" step="1" min="0" max="200"></x-text-input>
                    </div>
                </x-modal>
            </div>
            <div class="flex flex-col sm:flex-row gap-x-4 p-2">
                <div>
                    <livewire:insights.omv.create.batches />
                </div>
                <div class="w-full">
                    <div class="flex flex-col h-full gap-y-4">
                        <div
                            :class="!timerIsRunning && recipe ? 'cal-glowing z-10' : (timerIsRunning ? 'cal-glow z-10' : '')"
                            :style="'--cal-glow-pos: -' + (timerProgressPosition * 100) + '%'"
                        >
                            <div class="bg-white dark:bg-neutral-800 bg-opacity-80 dark:bg-opacity-80 shadow rounded-lg flex w-full items-stretch">
                                <div class="flex justify-between grow mx-6 my-4">
                                    <div class="flex flex-col justify-center">
                                        <div class="flex items-center gap-x-3">
                                            <div class="text-xl" x-text="recipe ? recipe.name : '{{ __("Menunggu...") }}'"></div>
                                        </div>
                                        <div class="flex gap-x-3 text-neutral-500">
                                            <div x-show="recipe" x-cloak>
                                                <x-pill class="uppercase"><span class="uppercase" x-text="batchType"></span></x-pill>
                                            </div>
                                            <div class="text-sm uppercase mt-1" @click="startTimer()">
                                                <span>{{ __("Kode") }}</span>
                                                <span>{{ ": " }}</span>
                                                <span x-text="batchCode ? batchCode.toUpperCase() : '{{ __("Tak ada") }}'"></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center">
                                        <div
                                            class="text-2xl font-mono"
                                            x-text="formatTime(timerRemainingTime)"
                                            x-show="timerIsRunning"
                                            :class="timerRemainingTime == 0 ? 'text-red-500' : ''"
                                        ></div>
                                    </div>
                                </div>

                                <x-secondary-button class="my-4" type="button" size="lg" x-show="recipe" x-cloak x-on:click="$dispatch('open-modal', 'details')">
                                    <span :class="(!batchTeam || !userq || !batchCode) ? 'text-red-500' : ''">
                                        {{ __("Rincian") }}
                                        <i x-show="!batchTeam || !userq || !batchCode" class="icon-circle-alert ml-3"></i>
                                    </span>
                                </x-secondary-button>

                                <x-primary-button class="m-4" type="button" size="lg" @click="wizardOpen()" x-show="!timerIsRunning && !recipe">
                                    {{ __("Mulai") }}
                                </x-primary-button>

                                <x-primary-button
                                    class="m-4"
                                    type="button"
                                    size="lg"
                                    x-cloak
                                    @click="reset(['timer', 'recipe', 'batch', 'poll', 'recipesFiltered', 'slider'])"
                                    x-show="!timerIsRunning && recipe"
                                >
                                    {{ __("Batal") }}
                                </x-primary-button>

                                <x-primary-button class="m-4" type="button" size="lg" @click="$dispatch('open-spotlight', 'manual-stop')" x-cloak x-show="timerIsRunning">
                                    <div>
                                        <div>{{ __("Stop") }}</div>
                                        <div class="relative bg-neutral-200 rounded-full h-1.5 dark:bg-neutral-700 mt-1">
                                            <div
                                                class="bg-caldy-600 h-1.5 rounded-full dark:bg-caldy-500 transition-all duration-200"
                                                :style="'width: ' + Math.min(timerEvalFalseCount / evalFalseLimit * 100, 100) + '%'"
                                            ></div>
                                        </div>
                                    </div>
                                </x-primary-button>
                            </div>
                        </div>

                        <x-spotlight name="sending" maxWidth="sm">
                            <div class="w-full flex items-center flex-col gap-y-6 pb-10 text-center">
                                <div class="relative w-16 h-16">
                                    <x-spinner></x-spinner>
                                </div>
                                <header>
                                    <h2 class="text-xl font-medium">
                                        {{ __("Mengirim data ke server...") }}
                                    </h2>
                                </header>
                            </div>
                        </x-spotlight>

                        <x-spotlight name="manual-stop" maxWidth="sm">
                            <div class="w-full flex flex-col gap-y-6 pb-10 text-center">
                                <div>
                                    <i class="text-4xl icon-triangle-alert"></i>
                                </div>
                                <header>
                                    <h2 class="text-xl font-medium uppercase">
                                        {{ __("Peringatan") }}
                                    </h2>
                                </header>
                                <div>
                                    {{ __("Disarankan untuk tidak menghentikan timer secara manual karena akan mempengaruhi evaluasi kerjamu. Jika ingin tetap menghentikan timer, geser ke kanan.") }}
                                </div>
                                <div class="flex items-center justify-center select-none">
                                    <div class="relative w-80 h-14 bg-neutral-300 dark:bg-neutral-700 rounded-full shadow-inner overflow-hidden">
                                        <div class="ml-8 absolute text-sm inset-0 flex items-center justify-center text-neutral-600 dark:text-neutral-400 cal-shimmer">
                                            {{ __("geser untuk menghentikan") }}
                                        </div>
                                        <div
                                            x-show="!sliderUnlocked"
                                            @mousedown="sliderStartDrag"
                                            @touchstart="sliderStartDrag"
                                            :style="`transform: translateX(${sliderCurrentX}px)`"
                                            class="absolute left-[.3rem] top-1 w-12 h-12 bg-white dark:bg-neutral-800 text-neutral-800 dark:text-white rounded-full shadow cursor-pointer transition-transform duration-100 ease-out flex items-center justify-center"
                                        >
                                            <i class="icon-arrow-right"></i>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex justify-center">
                                    <x-secondary-button @click="window.dispatchEvent(escKey)" type="button">{{ __("Kembali") }}</x-secondary-button>
                                </div>
                            </div>
                        </x-spotlight>

                        <x-modal name="omv-worker-unavailable" maxWidth="sm">
                            <div class="text-center pt-6">
                                <i class="icon-triangle-alert text-4xl"></i>
                                <h2 class="mt-3 text-lg font-medium text-neutral-900 dark:text-neutral-100">
                                    {{ __("omv-worker tidak merespon") }}
                                </h2>
                            </div>
                            <div class="p-6">
                                <p class="mt-3 text-sm text-neutral-600 dark:text-neutral-400">
                                    {{ __("Caldera perlu bersandingan dengan aplikasi omv-worker untuk berkomunikasi dengan sensor dan kamera.") }}
                                </p>
                                <p class="mt-3 text-sm text-neutral-600 dark:text-neutral-400">
                                    {{ __("Kamu dapat mengabaikan pesan ini dan lanjut menggunakan aplikasi, namun data tidak akan terkirim ke server.") }}
                                </p>
                                <p class="mt-3 text-sm text-neutral-600 dark:text-neutral-400">
                                    {{ __("Hubungi penanggung jawab/PIC Caldera untuk memperbaiki masalah ini.") }}
                                </p>
                                <div class="mt-6 flex justify-end">
                                    <x-primary-button type="button" x-on:click="$dispatch('close')">
                                        {{ __("Oke") }}
                                    </x-primary-button>
                                </div>
                            </div>
                        </x-modal>

                        <x-modal name="input-incomplete" maxWidth="sm">
                            <div class="text-center pt-6">
                                <i class="icon-triangle-alert text-4xl"></i>
                                <h2 class="mt-3 text-lg font-medium text-neutral-900 dark:text-neutral-100">
                                    {{ __("Rincian belum lengkap") }}
                                </h2>
                            </div>
                            <div class="p-6">
                                <p class="mt-3 text-sm text-neutral-600 dark:text-neutral-400">
                                    {{ __("Harap lengkapi Tim, Mitra kerja, dan Kode sebelum timer berhenti agar data sah dan dapat tersimpan.") }}
                                </p>
                                <p class="mt-3 text-sm text-neutral-600 dark:text-neutral-400">
                                    {{ __("Kelalaian dalam melengkapi informasi tersebut dapat menyebabkan datamu diabaikan oleh sistem.") }}
                                </p>
                                <div class="mt-6 flex justify-end">
                                    <x-primary-button type="button" x-on:click="$dispatch('close')">
                                        {{ __("Paham") }}
                                    </x-primary-button>
                                </div>
                            </div>
                        </x-modal>
                        <!-- modal details -->
                        <x-modal name="details" maxWidth="2xl" focusable>
                            <div>
                                <div class="flex justify-between items-start p-6">
                                    <h2 class="text-lg font-medium text-neutral-900 dark:text-neutral-100">
                                        {{ __("Rincian") }}
                                    </h2>
                                    <x-text-button type="button" x-on:click="$dispatch('close')"><i class="icon-x"></i></x-text-button>
                                </div>
                                <div class="grid grid-cols-6">
                                    <div class="col-span-2 p-6 bg-caldy-500 bg-opacity-10 rounded-r-xl">
                                        <x-pill class="uppercase">{{ __("Batch") }}</x-pill>
                                        <div class="mt-6">
                                            <label for="batchCode" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Kode") }}</label>
                                            <x-text-input id="batchCode" x-model="batchCode" type="text" />
                                        </div>
                                        <div class="mt-6">
                                            <label for="batchModel" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Model") }}</label>
                                            <x-input-autocomplete 
                                                id="batchModel" 
                                                wire:model="batchModel" 
                                                searchRoute="autocomplete.rubber-models"
                                                placeholder="Type to search models..." 
                                            />
                                        </div>
                                        <div class="mt-6">
                                            <label for="batchColor" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Warna") }}</label>
                                            <x-input-autocomplete 
                                                id="batchColor" 
                                                wire:model="batchColor" 
                                                searchRoute="autocomplete.rubber-colors"
                                                placeholder="Type to search colors..." 
                                            />
                                        </div>
                                        <div class="mt-6">
                                            <label for="batchMCS" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("MCS") }}</label>
                                            <x-text-input id="batchMCS" x-model="batchMCS" type="text" />
                                        </div>
                                    </div>
                                    <div class="col-span-4 px-6 flex flex-col gap-y-6">
                                        <div>
                                            <x-pill class="uppercase mb-6">{{ __("Pelaksana") }}</x-pill>
                                            <div class="grid grid-cols-3 gap-x-3">
                                                <div>
                                                    <label for="batchTeam" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Tim") }}</label>
                                                    <x-select id="batchTeam" class="w-full" x-model="batchTeam">
                                                        <option value=""></option>
                                                        <option value="A">A</option>
                                                        <option value="B">B</option>
                                                        <option value="C">C</option>
                                                    </x-select>
                                                </div>
                                                <div x-data="{ open: false }" class="col-span-2" x-on:user-selected="userq = $event.detail.user_emp_id; open = false">
                                                    <div x-on:click.away="open = false">
                                                        <label for="omv-user" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Mitra kerja") }}</label>
                                                        <x-text-input-icon
                                                            x-model="userq"
                                                            icon="icon-user"
                                                            x-on:change="open = true"
                                                            x-ref="userq"
                                                            x-on:focus="open = true"
                                                            id="omv-user"
                                                            type="text"
                                                            autocomplete="off"
                                                            placeholder="{{ __('Pengguna') }}"
                                                        />
                                                        <div class="relative" x-show="open" x-cloak>
                                                            <div class="absolute top-1 left-0 w-full z-10">
                                                                <livewire:layout.user-select />
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div>
                                            <x-pill class="uppercase mb-6">{{ __("Komposisi") }}</x-pill>
                                            <div class="grid grid-cols-2 gap-3">
                                                <div>
                                                    <label for="comp0" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Berat base original") }}</label>
                                                    <x-text-input-suffix suffix="kg" id="comp0" x-model="comp0" type="number" />
                                                </div>
                                                <div>
                                                    <label for="comp1" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Berat batch remixing") }}</label>
                                                    <x-text-input-suffix suffix="kg" id="comp1" x-model="comp1" type="number" />
                                                </div>
                                                <div>
                                                    <label for="comp2" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Berat skrap") }}</label>
                                                    <x-text-input-suffix suffix="kg" id="comp2" x-model="comp2" type="number" />
                                                </div>
                                            </div>
                                            <hr class="border-neutral-300 dark:border-neutral-700 my-6" />
                                            <div class="grid grid-cols-2 gap-3">
                                                <div>
                                                    <label for="comp3" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Pigmen") }}</label>
                                                    <x-text-input-suffix suffix="gr" id="comp3" x-model="comp3" type="number" />
                                                </div>
                                                <div>
                                                    <label for="comp4" class="block px-3 mb-2 uppercase text-xs text-neutral-500">IS75</label>
                                                    <x-text-input-suffix suffix="gr" id="comp4" x-model="comp4" type="number" />
                                                </div>
                                                <div>
                                                    <label for="comp5" class="block px-3 mb-2 uppercase text-xs text-neutral-500">RM001</label>
                                                    <x-text-input-suffix suffix="gr" id="comp5" x-model="comp5" type="number" />
                                                </div>
                                                <div>
                                                    <label for="comp6" class="block px-3 mb-2 uppercase text-xs text-neutral-500">TBZTD</label>
                                                    <x-text-input-suffix suffix="gr" id="comp6" x-model="comp6" type="number" />
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="p-6 flex justify-end">
                                <x-primary-button type="button" x-on:click="$dispatch('close')">{{ __("Simpan") }}</x-primary-button>
                            </div>
                        </x-modal>
                        <!-- end modal details -->
                        <x-modal name="recipes" focusable>
                            <div class="p-6">
                                <!-- Step 2: Mixing Type -->
                                <div x-show="wizardStep === 1">
                                    <h2 class="text-lg font-medium text-neutral-900 dark:text-neutral-100">
                                        {{ __("Pilih tipe mixing") }}
                                    </h2>
                                    <fieldset class="grid gap-2 mt-6">
                                        <div>
                                            <input
                                                type="radio"
                                                name="batchType"
                                                id="batchTypeNew"
                                                class="peer hidden [&:checked_+_label_svg]:block"
                                                value="new"
                                                x-model="batchType"
                                            />
                                            <label
                                                for="batchTypeNew"
                                                @click="setTimeout(() => { wizardNextStep() }, 200);"
                                                class="block h-full cursor-pointer rounded-lg border border-neutral-200 dark:border-neutral-700 p-4 hover:border-neutral-300 dark:hover:border-neutral-700 peer-checked:border-caldy-500 peer-checked:ring-1 peer-checked:ring-caldy-500"
                                            >
                                                <div class="flex items-center justify-between">
                                                    <p>{{ __("Baru") }}</p>
                                                    <svg class="hidden h-6 w-6 text-caldy-600" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                        <path
                                                            fill-rule="evenodd"
                                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                            clip-rule="evenodd"
                                                        />
                                                    </svg>
                                                </div>
                                            </label>
                                        </div>
                                        <div>
                                            <input
                                                type="radio"
                                                name="batchType"
                                                id="batchTypeRemixing"
                                                class="peer hidden [&:checked_+_label_svg]:block"
                                                value="remixing"
                                                x-model="batchType"
                                            />
                                            <label
                                                for="batchTypeRemixing"
                                                @click="setTimeout(() => { wizardNextStep() }, 200);"
                                                class="block h-full cursor-pointer rounded-lg border border-neutral-200 dark:border-neutral-700 p-4 hover:border-neutral-300 dark:hover:border-neutral-700 peer-checked:border-caldy-500 peer-checked:ring-1 peer-checked:ring-caldy-500"
                                            >
                                                <div class="flex items-center justify-between">
                                                    <p>{{ __("Remixing") }}</p>
                                                    <svg class="hidden h-6 w-6 text-caldy-600" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                        <path
                                                            fill-rule="evenodd"
                                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                            clip-rule="evenodd"
                                                        />
                                                    </svg>
                                                </div>
                                            </label>
                                        </div>
                                        <div>
                                            <input
                                                type="radio"
                                                name="batchType"
                                                id="batchTypeScrap"
                                                class="peer hidden [&:checked_+_label_svg]:block"
                                                value="scrap"
                                                x-model="batchType"
                                            />
                                            <label
                                                for="batchTypeScrap"
                                                @click="setTimeout(() => { wizardNextStep() }, 200);"
                                                class="block h-full cursor-pointer rounded-lg border border-neutral-200 dark:border-neutral-700 p-4 hover:border-neutral-300 dark:hover:border-neutral-700 peer-checked:border-caldy-500 peer-checked:ring-1 peer-checked:ring-caldy-500"
                                            >
                                                <div class="flex items-center justify-between">
                                                    <p>{{ __("Scrap") }}</p>
                                                    <svg class="hidden h-6 w-6 text-caldy-600" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                        <path
                                                            fill-rule="evenodd"
                                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                            clip-rule="evenodd"
                                                        />
                                                    </svg>
                                                </div>
                                            </label>
                                        </div>
                                    </fieldset>
                                </div>

                                <!-- Step 3: Recipe Selection -->
                                <div x-show="wizardStep === 2">
                                    <h2 class="text-lg font-medium text-neutral-900 dark:text-neutral-100">
                                        {{ __("Pilih resep") }}
                                    </h2>
                                    <fieldset class="grid gap-2 mt-6 max-h-96 overflow-y-scroll p-1">
                                        <template x-if="recipesFiltered.length > 0">
                                            <template x-for="recipe in recipesFiltered" :key="recipe.id">
                                                <div>
                                                    <input
                                                        type="radio"
                                                        name="recipe"
                                                        :id="'recipe-' + recipe.id"
                                                        class="peer hidden [&:checked_+_label_svg]:block"
                                                        :value="recipe.id"
                                                        x-model="recipeId"
                                                    />
                                                    <label
                                                        :for="'recipe-' + recipe.id"
                                                        class="block h-full cursor-pointer rounded-lg border border-neutral-200 dark:border-neutral-700 p-4 hover:border-neutral-300 dark:hover:border-neutral-700 peer-checked:border-caldy-500 peer-checked:ring-1 peer-checked:ring-caldy-500"
                                                    >
                                                        <div class="flex items-center justify-between">
                                                            <p x-text="recipe.name"></p>
                                                            <svg class="hidden h-6 w-6 text-caldy-600" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                                                <path
                                                                    fill-rule="evenodd"
                                                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                                                    clip-rule="evenodd"
                                                                />
                                                            </svg>
                                                        </div>
                                                    </label>
                                                </div>
                                            </template>
                                        </template>
                                        <template x-if="recipesFiltered.length === 0">
                                            <div class="text-center text-neutral-500">
                                                {{ __("Tidak ada resep untuk tipe ini") }}
                                            </div>
                                        </template>
                                    </fieldset>
                                </div>

                                <!-- Navigation buttons -->
                                <div class="flex mt-8 justify-end gap-x-3">
                                    <x-secondary-button type="button" x-show="wizardStep > 1" @click="wizardPrevStep">
                                        {{ __("Mundur") }}
                                    </x-secondary-button>
                                    <x-secondary-button type="button" x-show="wizardStep < 2 && recipeId" @click="wizardNextStep">
                                        {{ __("Maju") }}
                                    </x-secondary-button>
                                    <x-primary-button type="button" x-show="wizardStep === 2 && recipeId" @click="wizardFinish">
                                        {{ __("Terapkan") }}
                                    </x-primary-button>
                                </div>
                            </div>
                        </x-modal>

                        <div x-show="!recipe" class="grow">
                            <div class="relative overflow-hidden bg-white dark:bg-neutral-800 bg-opacity-80 dark:bg-opacity-80 shadow rounded-lg h-full flex items-center">
                                <svg wire:ignore class="absolute top-0 left-0 min-h-screen" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid slice">
                                    <defs>
                                        <radialGradient id="Gradient1" cx="50%" cy="50%" fx="0.441602%" fy="50%" r=".5">
                                            <animate attributeName="fx" dur="68s" values="0%;3%;0%" repeatCount="indefinite"></animate>
                                            <stop offset="0%" stop-color="rgba(255, 0, 255, 0.1)"></stop>
                                            <stop offset="100%" stop-color="rgba(255, 0, 255, 0)"></stop>
                                        </radialGradient>
                                        <radialGradient id="Gradient2" cx="50%" cy="50%" fx="2.68147%" fy="50%" r=".5">
                                            <animate attributeName="fx" dur="47s" values="0%;3%;0%" repeatCount="indefinite"></animate>
                                            <stop offset="0%" stop-color="rgba(255, 255, 0, 0.1)"></stop>
                                            <stop offset="100%" stop-color="rgba(255, 255, 0, 0)"></stop>
                                        </radialGradient>
                                        <radialGradient id="Gradient3" cx="50%" cy="50%" fx="0.836536%" fy="50%" r=".5">
                                            <animate attributeName="fx" dur="43s" values="0%;3%;0%" repeatCount="indefinite"></animate>
                                            <stop offset="0%" stop-color="rgba(0, 255, 255, 0.1)"></stop>
                                            <stop offset="100%" stop-color="rgba(0, 255, 255, 0)"></stop>
                                        </radialGradient>
                                        <radialGradient id="Gradient4" cx="50%" cy="50%" fx="4.56417%" fy="50%" r=".5">
                                            <animate attributeName="fx" dur="46s" values="0%;5%;0%" repeatCount="indefinite"></animate>
                                            <stop offset="0%" stop-color="rgba(0, 255, 0, 0.1)"></stop>
                                            <stop offset="100%" stop-color="rgba(0, 255, 0, 0)"></stop>
                                        </radialGradient>
                                        <radialGradient id="Gradient5" cx="50%" cy="50%" fx="2.65405%" fy="50%" r=".5">
                                            <animate attributeName="fx" dur="49s" values="0%;5%;0%" repeatCount="indefinite"></animate>
                                            <stop offset="0%" stop-color="rgba(0, 0, 255, 0.1)"></stop>
                                            <stop offset="100%" stop-color="rgba(0, 0, 255, 0)"></stop>
                                        </radialGradient>
                                        <radialGradient id="Gradient6" cx="50%" cy="50%" fx="0.981338%" fy="50%" r=".5">
                                            <animate attributeName="fx" dur="51s" values="0%;5%;0%" repeatCount="indefinite"></animate>
                                            <stop offset="0%" stop-color="rgba(255, 0, 0, 0.1)"></stop>
                                            <stop offset="100%" stop-color="rgba(255, 0, 0, 0)"></stop>
                                        </radialGradient>
                                    </defs>
                                    <rect x="13.744%" y="1.18473%" width="100%" height="100%" fill="url(#Gradient1)" transform="rotate(334.41 50 50)">
                                        <animate attributeName="x" dur="40s" values="25%;0%;25%" repeatCount="indefinite"></animate>
                                        <animate attributeName="y" dur="42s" values="0%;25%;0%" repeatCount="indefinite"></animate>
                                        <animateTransform
                                            attributeName="transform"
                                            type="rotate"
                                            from="0 50 50"
                                            to="360 50 50"
                                            dur="14s"
                                            repeatCount="indefinite"
                                        ></animateTransform>
                                    </rect>
                                    <rect x="-2.17916%" y="35.4267%" width="100%" height="100%" fill="url(#Gradient2)" transform="rotate(255.072 50 50)">
                                        <animate attributeName="x" dur="46s" values="-25%;0%;-25%" repeatCount="indefinite"></animate>
                                        <animate attributeName="y" dur="48s" values="0%;50%;0%" repeatCount="indefinite"></animate>
                                        <animateTransform
                                            attributeName="transform"
                                            type="rotate"
                                            from="0 50 50"
                                            to="360 50 50"
                                            dur="24s"
                                            repeatCount="indefinite"
                                        ></animateTransform>
                                    </rect>
                                    <rect x="9.00483%" y="14.5733%" width="100%" height="100%" fill="url(#Gradient3)" transform="rotate(139.903 50 50)">
                                        <animate attributeName="x" dur="50s" values="0%;25%;0%" repeatCount="indefinite"></animate>
                                        <animate attributeName="y" dur="24s" values="0%;25%;0%" repeatCount="indefinite"></animate>
                                        <animateTransform
                                            attributeName="transform"
                                            type="rotate"
                                            from="360 50 50"
                                            to="0 50 50"
                                            dur="18s"
                                            repeatCount="indefinite"
                                        ></animateTransform>
                                    </rect>
                                </svg>
                                <div class="grow py-20">
                                    <div class="text-center text-neutral-300 dark:text-neutral-700 text-5xl mb-3">
                                        <i class="icon-flask-conical relative">
                                            <i class="icon-circle-help absolute bottom-0 -right-1 text-lg text-neutral-500 dark:text-neutral-400"></i>
                                        </i>
                                    </div>
                                    <div class="text-center text-neutral-400 dark:text-neutral-600">
                                        {{ __("Belum ada resep yang dipilih") }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div x-show="recipe" class="grid grid-cols-2 gap-4">
                            <template x-for="(step, index) in recipeSteps" :key="index">
                                <div class="bg-white dark:bg-neutral-800 shadow rounded-lg p-4" :class="timerStepIndex == index && timerIsRunning ? 'cal-shimmer' : ''">
                                    <div class="flex gap-4 w-full mb-6">
                                        <div
                                            class="w-12 h-12 rounded-full flex items-center justify-center"
                                            :class="(timerStepIndex > index && timerIsRunning) ?
                                            'bg-green-500 text-neutral-800' : ((
                                                    timerStepIndex == index && timerIsRunning) ?
                                                'bg-yellow-500 text-neutral-800' :
                                                'bg-neutral-800 dark:bg-neutral-200 text-white dark:text-neutral-800')"
                                        >
                                            <span class="text-2xl font-bold" x-text="index + 1"></span>
                                        </div>
                                        <div class="grow">
                                            <div class="flex gap-x-3 items-center h-6 mb-2">
                                                <i x-show="timerStepIndex == index && timerIsRunning" class="icon-loader-circle icon-spin leading-none"></i>
                                                <div
                                                    class="grow text-xs uppercase"
                                                    :class="timerStepIndex == index && timerIsRunning ? 'icon-fade' : ''"
                                                    x-text="
                                                        timerStepIndex > index && timerIsRunning
                                                            ? '{{ __("Selesai") }}'
                                                            : timerStepIndex == index && timerIsRunning
                                                              ? '{{ __("Berjalan") }}'
                                                              : '{{ __("Menunggu") }}'
                                                    "
                                                ></div>
                                                <div class="text-xs font-mono" x-text="formatTime(timerStepRemainingTimes[index])"></div>
                                            </div>
                                            <div class="relative w-full bg-neutral-200 rounded-full h-1.5 dark:bg-neutral-700">
                                                <div
                                                    class="bg-caldy-600 h-1.5 rounded-full dark:bg-caldy-500 transition-all duration-200"
                                                    :style="'width: ' + timerStepPercentages[index] + '%'"
                                                ></div>
                                                <!-- Capture points -->
                                                <template
                                                    x-for="
                                                        point in
                                                            recipeCapturePoints.filter(
                                                                (p) =>
                                                                    p >= getPreviousStepsDuration(index) &&
                                                                    p < getPreviousStepsDuration(index + 1),
                                                            )
                                                    "
                                                    :key="point"
                                                >
                                                    <div
                                                        class="absolute w-2 h-2 bg-caldy-500 rounded-full top-4 transform -translate-y-1/2"
                                                        :style="'left: ' + ((point - getPreviousStepsDuration(index)) / step
                                                            .duration *
                                                            100) + '%'"
                                                        :class="timerElapsedSeconds >= point ? 'opacity-30' : ''"
                                                    ></div>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="text-2xl" x-text="step.description"></span>
                                        <span class="opacity-30"></span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script>
            const configDefaults = {
                captureThreshold: 1,
                evalTolerance: 120,
                evalFalseLimit: 20, // Auto stop: e.g. pollingBInterval: 4000, evalFalseLimit: 30, then 4000*30/1000 = 120 seconds autostop
                overtimeMaxDuration: 600,
                pollingAInterval: 4000,
                pollingBInterval: 4000,
                thresholdAmp: 100,
            };

            const batchDefaults = {
                batchCode: '',
                batchModel: '',
                batchColor: '',
                batchMCS: '',
                batchEval: '',
                batchType: '',
                batchAmps: [],
                batchImages: [],
                batchStartTime: null,
            };

            const compDefaults = {
                comp0: '',
                comp1: '',
                comp2: '',
                comp3: '',
                comp4: '',
                comp5: '',
                comp6: '',
            };

            const pollDefaults = {
                pollingAId: null,
                pollingBId: null,
            };

            const recipeDefaults = {
                recipeId: null,
                recipe: null,
                recipeDuration: 0,
                recipeCapturePoints: [],
                recipeSteps: [],
            };

            const timerDefaults = {
                timerCapturePoints: [],
                timerElapsedSeconds: 0,
                timerEvalFalseCount: 0,
                timerIntervalId: null,
                timerIsRunning: false,
                timerOvertime: false,
                timerOvertimeElapsed: 0,
                timerProgressPosition: 0, // glow
                timerRemainingTime: 0,
                timerStepIndex: 0,
                timerStepPercentages: [],
                timerStepRemainingTimes: [],
            };

            const sliderDefaults = {
                sliderUnlocked: false,
                sliderStartX: 0,
                sliderCurrentX: 0,
                sliderIsDragging: false,
            };

            function app() {
                return {
                    ...configDefaults,
                    ...batchDefaults,
                    ...compDefaults,
                    ...pollDefaults,
                    ...recipeDefaults,
                    ...timerDefaults,
                    ...sliderDefaults,
                    batchLine: '',
                    batchTeam: '',
                    recipes: [],
                    recipesFiltered: [],
                    statsVisible: false,
                    wizardStep: 1,

                    async fetchLine() {
                        if (!this.batchLine) {
                            try {
                                const response = await fetch('http://127.0.0.1:92/get-line');
                                if (!response.ok) {
                                    throw new Error('Failed to get line');
                                }
                                this.batchLine = await response.text();
                                this.$wire.dispatch('line-fetched', {
                                    line: this.batchLine,
                                });
                            } catch (error) {
                                console.error('Failed to fetch line:', error);
                            }
                        }
                    },

                    async loadRecipes() {
                        try {
                            const response = await fetch('/api/omv-recipes');
                            this.recipes = await response.json();
                        } catch (error) {
                            console.error('Failed to load recipes:', error);
                            this.recipes = [];
                        }
                        //     {
                        //         "id": 1,
                        //         "type": "new", // new property
                        //         "name": "Simple Recipe",
                        //         "capture_points": [5, 70],
                        //         "steps": [
                        //         {
                        //             "description": "Should be description of step 1 recipe",
                        //             "duration": "50"
                        //         },
                        //         {
                        //             "description": "Should be description of step 2 recipe",
                        //             "duration": "60"
                        //         }
                        //         ]
                        //     }, so on
                    },

                    reset(groups) {
                        if (groups.includes('batch')) {
                            Object.assign(this, batchDefaults);
                            Object.assign(this, compDefaults);
                        }
                        if (groups.includes('poll')) {
                            clearInterval(this.pollingAId);
                            clearInterval(this.pollingBId);
                            Object.assign(this, pollDefaults);
                        }
                        if (groups.includes('recipe')) {
                            Object.assign(this, recipeDefaults);
                        }
                        if (groups.includes('timer')) {
                            cancelAnimationFrame(this.timerIntervalId);
                            Object.assign(this, timerDefaults);
                        }
                        if (groups.includes('slider')) {
                            Object.assign(this, sliderDefaults);
                        }
                        if (groups.includes('recipesFiltered')) {
                            this.recipesFiltered = [];
                        }
                    },

                    loadRecipe(recipe) {
                        this.recipe = recipe;
                        this.recipeCapturePoints = recipe.capture_points || [];
                        this.recipeSteps = recipe.steps;
                        this.recipeDuration = Math.max(
                            0,
                            this.recipeSteps.reduce((sum, step) => {
                                const duration = parseFloat(step.duration) || 0; // Use parseFloat and handle NaN directly
                                return sum + duration;
                            }, 0) - 1,
                        );
                        this.timerStepPercentages = this.recipeSteps.map(() => 0);
                        this.timerStepRemainingTimes = this.recipeSteps.map((step) => step.duration);
                    },

                    startPollingA() {
                        this.reset(['poll']);
                        const recipe = this.recipes.find((r) => r.id == this.recipeId);
                        if (!recipe) {
                            toast('{{ __("Resep yang dipilih tidak sah.") }}', { type: 'danger' });
                            return;
                        }
                        this.loadRecipe(recipe);
                        this.$dispatch('close');

                        this.$dispatch('open-modal', 'details');

                        this.pollingAId = setInterval(() => {
                            this.fetchLine();
                            fetch('http://127.0.0.1:92/get-data')
                                .then((response) => response.json())
                                .then((data) => {
                                    console.log('Polling A:', data);
                                    if (data.error) {
                                        console.error('Polling A server error:', data.error);
                                    } else if (data.eval && !this.timerIsRunning) {
                                        this.startTimer();
                                        clearInterval(this.pollingAId);
                                    }
                                })
                                .catch((error) => {
                                    console.error('Polling A error:', error);
                                });
                        }, this.pollingAInterval);
                    },

                    wizardOpen() {
                        if (this.timerIsRunning) {
                            toast('{{ __("Hentikan timer sebelum memilih resep baru.") }}', { type: 'danger' });
                            return;
                        }

                        this.wizardStep = 1;
                        this.batchType = '';
                        this.recipeId = null;
                        this.$dispatch('open-modal', 'recipes');
                    },

                    wizardNextStep() {
                        if (this.wizardStep < 2) {
                            this.wizardStep++;
                            if (this.wizardStep === 2) {
                                this.filterRecipes();
                            }
                        }
                    },

                    wizardPrevStep() {
                        if (this.wizardStep > 1) {
                            this.wizardStep--;
                        }
                    },

                    wizardFinish() {
                        if (this.batchType && this.recipeId) {
                            this.startPollingA();
                        } else {
                            toast('{{ __("Tipe mixing dan resep wajib dipilih") }}', { type: 'danger' });
                        }
                    },

                    // Add this new method to filter recipes based on type
                    filterRecipes() {
                        this.recipesFiltered = this.recipes.filter((recipe) => recipe.type === this.batchType);
                    },

                    modifyClass(id, action, className) {
                        const element = document.getElementById(id);
                        if (element && element.classList) {
                            if (action === 'remove') {
                                element.classList.remove(className);
                            } else if (action === 'add') {
                                element.classList.add(className);
                            }
                        }
                    },

                    startTimer() {
                        if (this.timerIsRunning) {
                            toast('{{ __("Timer sudah berjalan.") }}', { type: 'danger' });
                            return;
                        }

                        if (!this.recipeSteps.length) {
                            toast('{{ __("Belum ada resep yang di pilih.") }}', { type: 'danger' });
                            return;
                        }

                        // Timer start
                        this.batchStartTime = new Date();
                        this.timerElapsedSeconds = 0;
                        this.timerIsRunning = true;
                        this.timerRemainingTime = this.recipeDuration;

                        // Initial data fetch for time 0
                        fetch('http://127.0.0.1:92/get-data')
                            .then((response) => response.json())
                            .then((data) => {
                                // Set the very first data point at time 0
                                console.log('Polling B initial:', data);
                                this.batchAmps = [
                                    {
                                        taken_at: 0,
                                        value: data.raw || 0,
                                    },
                                ];
                            })
                            .catch((error) => {
                                console.error('Error getting initial data:', error);
                                this.batchAmps = [
                                    {
                                        taken_at: 0,
                                        value: 0, // fallback value if fetch fails
                                    },
                                ];
                            });

                        // Start tick and Polling B
                        this.tick();
                        this.startPollingB();

                        // Activate focus mode interface
                        this.modifyClass('cal-nav-main-links', 'remove', 'md:flex');
                        this.modifyClass('cal-nav-omv', 'add', 'hidden');
                        this.modifyClass('cal-nav-main-links-alt', 'remove', 'hidden');

                        // Show warning if batch or user is empty
                        if (!this.batchTeam || !this.userq || !this.batchCode) {
                            this.$dispatch('open-modal', 'input-incomplete');
                        }
                    },

                    startPollingB() {
                        clearInterval(this.pollingAId);
                        this.pollingAId = null;
                        this.pollingBId = setInterval(() => {
                            this.fetchLine();
                            fetch('http://127.0.0.1:92/get-data')
                                .then((response) => response.json())
                                .then((data) => {
                                    console.log('Polling B:', data);
                                    if (data.error) {
                                        this.timerEvalFalseCount = 0;
                                        console.error('Polling B server error:', data.error);
                                    } else {
                                        this.batchAmps.push({
                                            taken_at: this.timerElapsedSeconds,
                                            value: data.raw,
                                        });

                                        // Auto stop
                                        if (data.eval === false) {
                                            this.timerEvalFalseCount++;
                                            if (this.timerEvalFalseCount > this.evalFalseLimit) {
                                                this.stopTimer(true); // Pass true to indicate automatic stop
                                            }
                                        } else {
                                            this.timerEvalFalseCount = 0;
                                        }
                                    }
                                })
                                .catch((error) => {
                                    console.error('Polling B error:', error);
                                });
                        }, this.pollingBInterval);
                    },

                    stopTimer(isAutomatic = false) {
                        let batchEndTime = new Date();
                        let adjustedElapsedSeconds = this.timerElapsedSeconds;

                        if (isAutomatic) {
                            window.dispatchEvent(escKey);
                            const adjustmentTime = this.evalFalseLimit * (this.pollingBInterval / 1000);
                            adjustedElapsedSeconds -= adjustmentTime;
                            batchEndTime = new Date(batchEndTime.getTime() - adjustmentTime * 1000);
                        }

                        const difference = Math.abs(adjustedElapsedSeconds - this.recipeDuration);

                        if (difference <= this.evalTolerance && isAutomatic) {
                            this.batchEval = 'on_time';
                        } else if (difference <= this.evalTolerance && !isAutomatic) {
                            this.batchEval = 'on_time_manual';
                        } else if (adjustedElapsedSeconds < this.recipeDuration) {
                            this.batchEval = 'too_soon';
                        } else {
                            this.batchEval = 'too_late';
                        }

                        // Prepare and send the JSON data
                        const jsonData = {
                            server_url: '{{ route("home") }}',
                            server_ip: '{{ parse_url(url("/"), PHP_URL_HOST) }}',
                            recipe_id: this.recipe.id.toString(),
                            code: this.batchCode,
                            model: this.batchModel,
                            color: this.batchColor,
                            mcs: this.batchMCS,
                            line: this.batchLine,
                            team: this.batchTeam,
                            user_1_emp_id: '{{ Auth::user()->emp_id }}',
                            user_2_emp_id: this.userq,
                            eval: this.batchEval,
                            start_at: this.formatDateTime(this.batchStartTime),
                            end_at: this.formatDateTime(batchEndTime),
                            images: this.batchImages,
                            amps: this.batchAmps,
                            composition: [this.comp0, this.comp1, this.comp2, this.comp3, this.comp4, this.comp5, this.comp6],
                        };
                        this.sendData(jsonData);
                        this.reset(['timer', 'recipe', 'batch', 'poll', 'recipesFiltered', 'slider']);
                    },

                    tick() {
                        if (this.timerIsRunning) {
                            this.timerElapsedSeconds = (new Date() - this.batchStartTime) / 1000;

                            if (this.timerElapsedSeconds < this.recipeDuration + 1) {
                                this.timerRemainingTime = Math.max(0, this.recipeDuration - Math.floor(this.timerElapsedSeconds));
                                this.updateProgress(this.timerElapsedSeconds);

                                // Check for capture points
                                this.recipeCapturePoints.forEach((point) => {
                                    if (Math.abs(this.timerElapsedSeconds - point) < this.captureThreshold && !this.timerCapturePoints.includes(point)) {
                                        console.log(`Image capture point: ${point}, elapsed time: ${this.timerElapsedSeconds}`); // Debug log
                                        this.captureImage(this.getTimerStepIndex(this.timerElapsedSeconds), point);
                                        this.timerCapturePoints.push(point);
                                    }
                                });

                                this.timerProgressPosition = this.timerElapsedSeconds / this.recipeDuration;
                            } else {
                                this.timerRemainingTime = 0;
                                this.timerOvertime = true;
                                this.timerOvertimeElapsed = Math.floor(this.timerElapsedSeconds - this.recipeDuration);
                                this.timerProgressPosition = 1;

                                if (this.timerOvertimeElapsed >= this.overtimeMaxDuration) {
                                    this.stopTimer(true); // This will reset the recipe selection when the timer completes
                                    return;
                                }
                            }

                            this.timerIntervalId = requestAnimationFrame(() => this.tick());
                        }
                    },

                    updateProgress(elapsedSeconds) {
                        let stepStartTime = 0;
                        for (let i = 0; i < this.recipeSteps.length; i++) {
                            let stepDuration = this.recipeSteps[i].duration;
                            let stepEndTime = stepStartTime + stepDuration;

                            if (elapsedSeconds < stepEndTime) {
                                this.timerStepIndex = i;
                                let stepElapsedTime = elapsedSeconds - stepStartTime;
                                this.timerStepPercentages[i] = Math.min(100, ((stepElapsedTime + 2) / stepDuration) * 100);
                                this.timerStepRemainingTimes[i] = Math.max(0, stepDuration - Math.ceil(stepElapsedTime));
                                break;
                                // } else {
                                //     console.log('hehe');
                                //     this.timerStepPercentages[i] = 100;
                                //     this.timerStepRemainingTimes[i] = 0;
                            }
                            stepStartTime = stepEndTime;
                        }
                    },

                    formatTime(seconds) {
                        const minutes = Math.floor(seconds / 60);
                        const remainingSeconds = Math.floor(seconds % 60);
                        return `${minutes.toString().padStart(2, '0')}:${remainingSeconds.toString().padStart(2, '0')}`;
                    },

                    formatDateTime(date) {
                        const year = date.getFullYear();
                        const month = String(date.getMonth() + 1).padStart(2, '0');
                        const day = String(date.getDate()).padStart(2, '0');
                        const hours = String(date.getHours()).padStart(2, '0');
                        const minutes = String(date.getMinutes()).padStart(2, '0');
                        const seconds = String(date.getSeconds()).padStart(2, '0');

                        return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
                    },

                    sendData(jsonData) {
                        this.$dispatch('open-spotlight', 'sending');
                        fetch('http://127.0.0.1:92/send-data', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify(jsonData),
                        })
                            .then((response) => {
                                if (!response.ok) {
                                    throw new Error('Network response was not ok');
                                }
                                return response.json();
                            })
                            .then((data) => {
                                console.log('Success:', data);
                                toast('{{ __("Data terkirim") }}', { type: 'success' });
                            })
                            .catch((error) => {
                                console.error('Error:', error);
                                toast('{{ __("Data gagal terkirim") }}', { type: 'danger' });
                            })
                            .finally(() => {
                                this.modifyClass('cal-nav-main-links', 'add', 'sm:flex');
                                this.modifyClass('cal-nav-omv', 'remove', 'hidden');
                                this.modifyClass('cal-nav-main-links-alt', 'add', 'hidden');
                                window.dispatchEvent(escKey);
                            });
                    },

                    captureImage(stepIndex, capturePoint) {
                        console.log(`Image capture step index: ${stepIndex}, time: ${capturePoint}`);
                        fetch('http://127.0.0.1:92/get-photo')
                            .then((response) => response.blob())
                            .then((imageBlob) => {
                                return new Promise((resolve, reject) => {
                                    const reader = new FileReader();
                                    reader.onloadend = () => resolve(reader.result);
                                    reader.onerror = reject;
                                    reader.readAsDataURL(imageBlob);
                                });
                            })
                            .then((base64Image) => {
                                const elapsedSeconds = this.timerElapsedSeconds;
                                console.log(`Image capture success at: ${elapsedSeconds}`);
                                this.batchImages.push({
                                    step_index: stepIndex,
                                    taken_at: elapsedSeconds,
                                    image: base64Image,
                                });
                            })
                            .catch((error) => {
                                console.error('Error capturing image:', error);
                            });
                        // should do check if (data.error) exists
                    },

                    getPreviousStepsDuration(index) {
                        return this.recipeSteps.slice(0, index).reduce((sum, step) => sum + Number(step.duration), 0);
                    },

                    getTimerStepIndex(elapsedSeconds) {
                        let recipeDuration = 0;
                        for (let i = 0; i < this.recipeSteps.length; i++) {
                            recipeDuration += Number(this.recipeSteps[i].duration);
                            if (elapsedSeconds < recipeDuration) {
                                return i;
                            }
                        }
                        return this.recipeSteps.length - 1; // Return last step if elapsed time exceeds total duration
                    },

                    sliderStartDrag(event) {
                        if (this.unlocked) return;
                        this.sliderIsDragging = true;
                        this.sliderStartX = (event.clientX || event.touches[0].clientX) - this.sliderCurrentX;

                        document.addEventListener('mousemove', this.sliderDrag.bind(this));
                        document.addEventListener('touchmove', this.sliderDrag.bind(this));
                        document.addEventListener('mouseup', this.sliderEndDrag.bind(this));
                        document.addEventListener('touchend', this.sliderEndDrag.bind(this));
                    },

                    sliderDrag(event) {
                        if (!this.sliderIsDragging || this.sliderUnlocked) return;
                        const clientX = event.clientX || (event.touches && event.touches[0].clientX);
                        if (clientX) {
                            this.sliderCurrentX = Math.max(0, Math.min(clientX - this.sliderStartX, 262));
                        }
                    },
                    sliderEndDrag() {
                        if (this.sliderIsDragging) {
                            this.sliderIsDragging = false;
                            if (this.sliderCurrentX >= 262) {
                                this.sliderUnlocked = true;
                                window.dispatchEvent(escKey);
                                this.stopTimer();
                            } else {
                                this.sliderCurrentX = 0;
                            }
                        }

                        document.removeEventListener('mousemove', this.sliderDrag.bind(this));
                        document.removeEventListener('touchmove', this.sliderDrag.bind(this));
                        document.removeEventListener('mouseup', this.sliderEndDrag.bind(this));
                        document.removeEventListener('touchend', this.sliderEndDrag.bind(this));
                    },
                    sliderReset() {
                        this.sliderUnlocked = false;
                        this.sliderCurrentX = 0;
                        this.sliderIsDragging = false;
                    },
                };
            }
        </script>
    @endif
</div>
