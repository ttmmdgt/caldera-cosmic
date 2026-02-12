<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

use Carbon\Carbon;
use App\Models\InsRdcTest;
use App\Models\InsRdcMachine;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Traits\HasDateRangeFilter;

new class extends Component {
    use WithPagination;
    use HasDateRangeFilter;

    #[Url]
    public $start_at;

    #[Url]
    public $end_at;

    #[Url]
    public $machine_id;

    #[Url]
    public $mcs;

    #[Url]
    public $hasil;

    #[Url]
    public $status_test;

    #[Url]
    public $shift;

    public $perPage = 20;

    private function getTestsQuery()
    {
        $start = Carbon::parse($this->start_at);
        $end = Carbon::parse($this->end_at)->endOfDay();

        $this->mcs = trim($this->mcs);

        $query = InsRdcTest::join("ins_rubber_batches", "ins_rdc_tests.ins_rubber_batch_id", "=", "ins_rubber_batches.id")
            ->join("ins_rdc_machines", "ins_rdc_tests.ins_rdc_machine_id", "=", "ins_rdc_machines.id")
            ->join("users", "ins_rdc_tests.user_id", "=", "users.id")
            ->select(
                "ins_rdc_tests.*",
                "ins_rdc_tests.queued_at as test_queued_at",
                "ins_rdc_tests.created_at as test_created_at",
                "ins_rubber_batches.code as batch_code",
                "ins_rubber_batches.code_alt as batch_code_alt",
                "ins_rubber_batches.model as batch_model",
                "ins_rubber_batches.color as batch_color",
                "ins_rubber_batches.mcs as batch_mcs",
                "ins_rdc_machines.number as machine_number",
                "users.emp_id as user_emp_id",
                "users.name as user_name",
            )
            ->whereBetween("ins_rdc_tests.created_at", [$start, $end]);

        if ($this->machine_id) {
            $query->where("ins_rdc_tests.ins_rdc_machine_id", $this->machine_id);
        }

        if ($this->mcs) {
            $query->where("ins_rubber_batches.mcs", $this->mcs);
        }

        if ($this->hasil) {
            $query->where("ins_rdc_tests.eval", $this->hasil);
        }

        if ($this->status_test) {
            $query->where("ins_rdc_tests.status_test", $this->status_test);
        }

        if ($this->shift) {
            $query->where("ins_rdc_tests.shift", $this->shift);
        }

        return $query->orderBy("updated_at", "DESC");
    }

    public function mount()
    {
        if (! $this->start_at || ! $this->end_at) {
            $this->setToday();
        }
    }

    public function with(): array
    {
        $tests = $this->getTestsQuery()->paginate($this->perPage);
        $machines = InsRdcMachine::where("is_active", true)
            ->orderBy("number")
            ->get();

        return [
            "tests" => $tests,
            "machines" => $machines,
        ];
    }

    public function loadMore()
    {
        $this->perPage += 10;
    }

    public function download()
    {
        $filename = "rdc_tests_export_" . now()->format("Y-m-d_His") . ".csv";

        $headers = [
            "Content-type" => "text/csv",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma" => "no-cache",
            "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
            "Expires" => "0",
        ];

        $columns = [__("Diperbarui"), __("Kode"), __("Kode alternatif"), __("Model"), __("Warna"), __("MCS"), __("Hasil"), __("Mesin"), __("Tag"), __("Nama"), __("Waktu antri")];

        $callback = function () use ($columns) {
            $file = fopen("php://output", "w");
            fputcsv($file, $columns);

            $this->getTestsQuery()->chunk(1000, function ($tests) use ($file) {
                foreach ($tests as $test) {
                    fputcsv($file, [
                        $test->test_updated_at,
                        $test->batch_code,
                        $test->batch_code_alt,
                        $test->batch_model ?? "-",
                        $test->batch_color ?? "-",
                        $test->batch_mcs ?? "-",
                        $test->evalHuman(),
                        $test->machine_number,
                        $test->tag,
                        $test->user_name,
                        $test->test_queued_at,
                    ]);
                }
            });

            fclose($file);
        };

        return new StreamedResponse($callback, 200, $headers);
    }
};

?>

<div>
    <div class="p-0 sm:p-1 mb-6">
        <div class="flex flex-col lg:flex-row gap-3 w-full bg-white dark:bg-neutral-800 shadow sm:rounded-lg p-4">
            <div>
                <div class="flex mb-2 text-xs text-neutral-500">
                    <div class="flex">
                        <x-dropdown align="left" width="48">
                            <x-slot name="trigger">
                                <x-text-button class="uppercase ml-3">
                                    {{ __("Rentang") }}
                                    <i class="icon-chevron-down ms-1"></i>
                                </x-text-button>
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link href="#" wire:click.prevent="setToday">
                                    {{ __("Hari ini") }}
                                </x-dropdown-link>
                                <x-dropdown-link href="#" wire:click.prevent="setYesterday">
                                    {{ __("Kemarin") }}
                                </x-dropdown-link>
                                <hr class="border-neutral-300 dark:border-neutral-600" />
                                <x-dropdown-link href="#" wire:click.prevent="setThisWeek">
                                    {{ __("Minggu ini") }}
                                </x-dropdown-link>
                                <x-dropdown-link href="#" wire:click.prevent="setLastWeek">
                                    {{ __("Minggu lalu") }}
                                </x-dropdown-link>
                                <hr class="border-neutral-300 dark:border-neutral-600" />
                                <x-dropdown-link href="#" wire:click.prevent="setThisMonth">
                                    {{ __("Bulan ini") }}
                                </x-dropdown-link>
                                <x-dropdown-link href="#" wire:click.prevent="setLastMonth">
                                    {{ __("Bulan lalu") }}
                                </x-dropdown-link>
                            </x-slot>
                        </x-dropdown>
                    </div>
                </div>
                <div class="flex gap-3">
                    <x-text-input wire:model.live="start_at" id="cal-date-start" type="date"></x-text-input>
                    <x-text-input wire:model.live="end_at" id="cal-date-end" type="date"></x-text-input>
                </div>
            </div>
            <div class="border-l border-neutral-300 dark:border-neutral-700 mx-2"></div>
            <div class="grid grid-cols-2 lg:flex gap-3">
                <div>
                    <label for="tests-machine" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("MC") }}</label>
                    <x-select class="w-full lg:w-auto" id="tests-machine" wire:model.live="machine_id">
                        <option value=""></option>
                        @foreach ($machines as $machine)
                            <option value="{{ $machine->id }}">{{ $machine->number }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-full lg:w-28">
                    <label for="tests-mcs" class="block px-3 mb-2 uppercase text-xs text-neutral-500">MCS</label>
                    <x-text-input id="tests-mcs" wire:model.live="mcs" type="text" />
                </div>
                <div>
                    <label for="tests-hasil" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Hasil") }}</label>
                    <x-select class="w-full lg:w-auto" id="tests-hasil" wire:model.live="hasil">
                        <option value=""></option>
                        <option value="pass">{{ __("Pass") }}</option>
                        <option value="fail">{{ __("Fail") }}</option>
                        <option value="queue">{{ __("Queue") }}</option>
                    </x-select>
                </div>
                <!-- Status Test -->
                <div>
                    <label for="tests-status_test" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Status Test") }}</label>
                    <x-select class="w-full lg:w-auto" id="tests-status_test" wire:model.live="status_test">
                        <option value=""></option>
                        <option value="new">New</option>
                        <option value="retest">Retest</option>
                    </x-select>
                </div>
                <!-- End Status Test -->
                 <!-- shift -->
                <div>
                    <label for="tests-shift" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Shift") }}</label>
                    <x-select class="w-full lg:w-auto" id="tests-shift" wire:model.live="shift">
                        <option value=""></option>
                        <option value="A">A</option>
                        <option value="B">B</option>
                        <option value="C">C</option>
                    </x-select>
                </div>
                <!-- End Shift -->
            </div>
            <div class="border-l border-neutral-300 dark:border-neutral-700 mx-2"></div>
            <div class="grow flex justify-between gap-x-2 items-center">
                <div>
                    <div class="px-3">
                        <div wire:loading.class="hidden">{{ $tests->total() . " " . __("ditemukan") }}</div>
                        <div wire:loading.class.remove="hidden" class="flex gap-3 hidden">
                            <div class="relative w-3">
                                <x-spinner class="sm mono"></x-spinner>
                            </div>
                            <div>
                                {{ __("Memuat...") }}
                            </div>
                        </div>
                    </div>
                </div>
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <x-text-button><i class="icon-ellipsis-vertical"></i></x-text-button>
                    </x-slot>
                    <x-slot name="content">
                        <x-dropdown-link href="#" x-on:click.prevent="$dispatch('open-modal', 'raw-stats-info')">
                            <i class="me-2"></i>
                            {{ __("Statistik ") }}
                        </x-dropdown-link>
                        <hr class="border-neutral-300 dark:border-neutral-600" />
                        <x-dropdown-link href="#" wire:click.prevent="download">
                            <i class="icon-download me-2"></i>
                            {{ __("Unduh sebagai CSV") }}
                        </x-dropdown-link>
                    </x-slot>
                </x-dropdown>
            </div>
        </div>
    </div>
    <div wire:key="modals">
        <x-modal name="raw-stats-info">
            <div class="p-6">
                <h2 class="text-lg font-medium text-neutral-900 dark:text-neutral-100">
                    {{ __("Statistik data mentah") }}
                </h2>
                <p class="mt-3 text-sm text-neutral-600 dark:text-neutral-400">
                    {{ __("Belum ada informasi statistik yang tersedia.") }}
                </p>
                <div class="mt-6 flex justify-end">
                    <x-primary-button type="button" x-on:click="$dispatch('close')">
                        {{ __("Paham") }}
                    </x-primary-button>
                </div>
            </div>
        </x-modal>
        <x-modal name="batch-show" maxWidth="2xl">
            <livewire:insights.rubber-batch.show />
        </x-modal>
    </div>
    @if (! $tests->count())
        @if (! $start_at || ! $end_at)
            <div wire:key="no-range" class="py-20">
                <div class="text-center text-neutral-300 dark:text-neutral-700 text-5xl mb-3">
                    <i class="icon-calendar relative"><i class="icon-circle-help absolute bottom-0 -right-1 text-lg text-neutral-500 dark:text-neutral-400"></i></i>
                </div>
                <div class="text-center text-neutral-400 dark:text-neutral-600">{{ __("Pilih rentang tanggal") }}</div>
            </div>
        @else
            <div wire:key="no-match" class="py-20">
                <div class="text-center text-neutral-300 dark:text-neutral-700 text-5xl mb-3">
                    <i class="icon-ghost"></i>
                </div>
                <div class="text-center text-neutral-500 dark:text-neutral-600">{{ __("Tidak ada yang cocok") }}</div>
            </div>
        @endif
    @else
        <div wire:key="raw-tests" class="overflow-auto p-0 sm:p-1">
            <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg table">
                <table class="table table-sm text-sm table-truncate text-neutral-600 dark:text-neutral-400">
                    <tr class="uppercase text-xs">
                        <th>{{ __("Selesai") }}</th>
                        <th>{{ __("Kode") }}</th>
                        <th>{{ __("Kode alt") }}</th>
                        <th>{{ __("Model") }}</th>
                        <th>{{ __("Warna") }}</th>
                        <th>{{ __("MCS") }}</th>
                        <th>{{ __("Type") }}</th>
                        <th>{{ __("TC10") }}</th>
                        <th>{{ __("TC90") }}</th>
                        <th>{{ __("Hasil") }}</th>
                        <th>{{ __("M") }}</th>
                        <th>{{ __("Shift") }}</th>
                        <th>{{ __("Operator") }}</th>
                    </tr>
                    @foreach ($tests as $test)
                        <tr
                            wire:key="test-tr-{{ $test->id . $loop->index }}"
                            tabindex="0"
                            x-on:click="
                                $dispatch('open-modal', 'batch-show')
                                $dispatch('batch-show', { rdc_test_id: '{{ $test->id }}', view: 'rdc' })
                            "
                        >
                            <td>{{ $test->test_created_at }}</td>
                            <td>
                                {{ $test->batch_code }}
                                <x-pill
                                    class="uppercase text-xs"
                                    color="{{
                                $test->status_test === 'new' ? 'yellow' :
                                ($test->status_test === 'retest' ? 'blue' :
                                'neutral')
                                }}"
                                >
                                    {{ $test->status_test }}
                                </x-pill>
                            </td>
                            <td>{{ $test->batch_code_alt }}</td>
                            <td>{{ $test->batch_model ? $test->batch_model : "-" }}</td>
                            <td>{{ $test->batch_color ? $test->batch_color : "-" }}</td>
                            <td>{{ $test->batch_mcs ? $test->batch_mcs : "-" }}</td>
                            <td class="uppercase">{{ $test->type }}</td>
                            <td>{{ $test->tc10 }}</td>
                            <td>{{ $test->tc90 }}</td>
                            <td>
                                <x-pill
                                    class="uppercase"
                                    color="{{
                                $test->eval === 'queue' ? 'yellow' :
                                ($test->eval === 'pass' ? 'green' :
                                ($test->eval === 'fail' ? 'red' : ''))
                                }}"
                                >
                                    {{ $test->evalHuman() }}
                                </x-pill>
                            </td>
                            <td>{{ $test->machine_number }}</td>
                            <td>{{ $test->shift ?? "-" }}</td>
                            <td>{{ ($test->user_emp_id ?? "") . " - " . ($test->user_name ?? "") }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
        <div class="flex items-center relative h-16">
            @if (! $tests->isEmpty())
                @if ($tests->hasMorePages())
                    <div
                        wire:key="more"
                        x-data="{
                        observe() {
                            const observer = new IntersectionObserver((tests) => {
                                tests.forEach(test => {
                                    if (test.isIntersecting) {
                                        @this.loadMore()
                                    }
                                })
                            })
                            observer.observe(this.$el)
                        }
                    }"
                        x-init="observe"
                    ></div>
                    <x-spinner class="sm" />
                @else
                    <div class="mx-auto">{{ __("Tidak ada lagi") }}</div>
                @endif
            @endif
        </div>
    @endif
</div>
