<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use App\Models\InsOmvMetric;
use Carbon\Carbon;

new class extends Component {
    public int $line = 0;

    #[On("line-fetched")]
    public function setLine($line)
    {
        $this->line = $line;
    }

    public function with(): array
    {
        $metrics = InsOmvMetric::where("updated_at", ">=", Carbon::now()->subDay())
            ->where("line", $this->line)
            ->orderBy("updated_at", "desc")
            ->get();

        return [
            "metrics" => $metrics,
        ];
    }
};

?>

<div wire:poll.5s class="w-64 bg-white dark:bg-neutral-800 bg-opacity-80 dark:bg-opacity-80 shadow rounded-lg">
    <div wire:key="modals">
        <x-modal name="batch-show" maxWidth="2xl">
            <livewire:insights.rubber-batch.show />
        </x-modal>
    </div>
    <div class="pt-6">
        <div class="flex justify-between text-neutral-500 text-sm px-6 pb-6 uppercase">
            <div>{{ __("Riwayat") }}</div>

            @if ($line)
                <div>{{ __("Line") . " " . $line }}</div>
            @else
                <div x-on:click="$dispatch('open-modal', 'omv-worker-unavailable')" class="text-red-500 cursor-pointer text-sm uppercase">
                    {{ __("Line") }}
                    <i class="icon-circle-alert ms-2"></i>
                </div>
            @endif
        </div>
        <hr class="border-neutral-200 dark:border-neutral-700 opacity-85" />
        <div class="overflow-y-scroll p-1 h-[520px]">
            @if ($metrics->isEmpty())
                <div class="flex items-center justify-center h-full">
                    <div>
                        <div class="text-center text-neutral-300 dark:text-neutral-700 text-5xl mb-3">
                            <i class="icon-history relative"><i class="icon-circle-help absolute bottom-0 -right-1 text-lg text-neutral-500 dark:text-neutral-400"></i></i>
                        </div>
                        <div class="text-center text-neutral-400 dark:text-neutral-600">{{ __("Tak ada riwayat") }}</div>
                    </div>
                </div>
            @else
                <ul class="py-3">
                    @foreach ($metrics as $metric)
                        <li class="w-full hover:bg-caldy-500 hover:bg-opacity-10">
                            <x-link
                                href="#"
                                x-on:click="$dispatch('open-modal', 'batch-show'); $dispatch('batch-show', { omv_metric_id: '{{ $metric->id }}', view: 'omv'})"
                                class="grid gap-y-1 px-6 py-3"
                            >
                                <div class="flex gap-x-1 text-sm text-neutral-500">
                                    <div class="w-4 h-4 my-auto bg-neutral-200 dark:bg-neutral-700 rounded-full overflow-hidden">
                                        @if ($metric->user_1->photo ?? false)
                                            <img class="w-full h-full object-cover dark:brightness-75" src="{{ "/storage/users/" . $metric->user_1->photo }}" />
                                        @else
                                            <svg
                                                xmlns="http://www.w3.org/2000/svg"
                                                class="block fill-current text-neutral-800 dark:text-neutral-200 opacity-25"
                                                viewBox="0 0 1000 1000"
                                                xmlns:v="https://vecta.io/nano"
                                            >
                                                <path
                                                    d="M621.4 609.1c71.3-41.8 119.5-119.2 119.5-207.6-.1-132.9-108.1-240.9-240.9-240.9s-240.8 108-240.8 240.8c0 88.5 48.2 165.8 119.5 207.6-147.2 50.1-253.3 188-253.3 350.4v3.8a26.63 26.63 0 0 0 26.7 26.7c14.8 0 26.7-12 26.7-26.7v-3.8c0-174.9 144.1-317.3 321.1-317.3S821 784.4 821 959.3v3.8a26.63 26.63 0 0 0 26.7 26.7c14.8 0 26.7-12 26.7-26.7v-3.8c.2-162.3-105.9-300.2-253-350.2zM312.7 401.4c0-103.3 84-187.3 187.3-187.3s187.3 84 187.3 187.3-84 187.3-187.3 187.3-187.3-84.1-187.3-187.3z"
                                                />
                                            </svg>
                                        @endif
                                    </div>
                                    @if ($metric->user_2)
                                        <div class="w-4 h-4 my-auto bg-neutral-200 dark:bg-neutral-700 rounded-full overflow-hidden">
                                            @if ($metric->user_2->photo ?? false)
                                                <img class="w-full h-full object-cover dark:brightness-75" src="{{ "/storage/users/" . $metric->user_2->photo }}" />
                                            @else
                                                <svg
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    class="block fill-current text-neutral-800 dark:text-neutral-200 opacity-25"
                                                    viewBox="0 0 1000 1000"
                                                    xmlns:v="https://vecta.io/nano"
                                                >
                                                    <path
                                                        d="M621.4 609.1c71.3-41.8 119.5-119.2 119.5-207.6-.1-132.9-108.1-240.9-240.9-240.9s-240.8 108-240.8 240.8c0 88.5 48.2 165.8 119.5 207.6-147.2 50.1-253.3 188-253.3 350.4v3.8a26.63 26.63 0 0 0 26.7 26.7c14.8 0 26.7-12 26.7-26.7v-3.8c0-174.9 144.1-317.3 321.1-317.3S821 784.4 821 959.3v3.8a26.63 26.63 0 0 0 26.7 26.7c14.8 0 26.7-12 26.7-26.7v-3.8c.2-162.3-105.9-300.2-253-350.2zM312.7 401.4c0-103.3 84-187.3 187.3-187.3s187.3 84 187.3 187.3-84 187.3-187.3 187.3-187.3-84.1-187.3-187.3z"
                                                    />
                                                </svg>
                                            @endif
                                        </div>
                                    @endif

                                    <div>•</div>
                                    <div>{{ __("Tim") . " " . $metric->team }}</div>
                                    <div class="grow"></div>
                                    <div>{{ $metric->updated_at->diffForHumans(["short" => true, "syntax" => Carbon::DIFF_ABSOLUTE]) }}</div>
                                </div>
                                <div class="uppercase">{{ $metric->ins_rubber_batch->code ?? __("Tanpa kode") }}</div>
                                <div class="flex flex-wrap gap-1 -mx-2 text-sm">
                                    <x-pill
                                        class="inline-block uppercase"
                                        color="{{ $metric->eval === 'on_time' ? 'green' : ($metric->eval === 'on_time_manual' ? 'yellow' : ($metric->eval === 'too_late' || $metric->eval === 'too_soon' ? 'red' : 'neutral')) }}"
                                    >
                                        {{ $metric->evalHuman() }}
                                    </x-pill>
                                    @if ($metric->ins_rubber_batch)
                                        <x-pill
                                            class="inline-block uppercase"
                                            color="{{ $metric->ins_rubber_batch->ins_rdc_test?->eval === 'queue' ? 'yellow' : ($metric->ins_rubber_batch->ins_rdc_test?->eval === 'pass' ? 'green' : ($metric->ins_rubber_batch->ins_rdc_test?->eval === 'fail' ? 'red' : 'neutral')) }}"
                                        >
                                            {{ "RHEO: " . $metric->ins_rubber_batch->ins_rdc_test?->evalHuman() ?: "N/A" }}
                                        </x-pill>
                                    @endif
                                </div>
                            </x-link>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
