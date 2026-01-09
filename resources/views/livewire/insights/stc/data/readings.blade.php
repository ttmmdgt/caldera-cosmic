<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Url;
use Livewire\Attributes\On;
use Livewire\WithPagination;

use Carbon\Carbon;
use App\InsStc;
use App\Models\InsStcDSum;
use App\Models\InsStcDLog;
use App\Models\InsStcMachine;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Traits\HasDateRangeFilter;

new class extends Component {
    use WithPagination;
    use HasDateRangeFilter;

    #[Url]
    public string $start_at = "";

    #[Url]
    public string $end_at = "";

    #[Url]
    public $line;

    #[Url]
    public string $position = "";

    public array $lines = [];

    public int $perPage = 10;

    private function getDSumsQuery()
    {
        $start = Carbon::parse($this->start_at);
        $end = Carbon::parse($this->end_at)->endOfDay();

        $query = InsStcDSum::join("ins_stc_machines", "ins_stc_d_sums.ins_stc_machine_id", "=", "ins_stc_machines.id")
            ->join("users", "ins_stc_d_sums.user_id", "=", "users.id")
            ->select(
                "ins_stc_d_sums.*",
                "ins_stc_d_sums.created_at as d_sum_created_at",
                "ins_stc_machines.line as machine_line",
                "ins_stc_machines.std_duration as machine_std_duration",
                "users.emp_id as user_emp_id",
                "users.name as user_name",
                "users.photo as user_photo",
            )
            ->whereBetween("ins_stc_d_sums.created_at", [$start, $end]);

        if ($this->line) {
            $query->where("ins_stc_machines.line", $this->line);
        }

        if ($this->position) {
            $query->where("ins_stc_d_sums.position", $this->position);
        }

        return $query->orderBy("created_at", "DESC");
    }

    private function getDLogsQuery()
    {
        $start = Carbon::parse($this->start_at);
        $end = Carbon::parse($this->end_at)->endOfDay();

        $query = InsStcDLog::join("ins_stc_d_sums", "ins_stc_d_logs.ins_stc_d_sum_id", "=", "ins_stc_d_sums.id")
            ->join("ins_stc_machines", "ins_stc_d_sums.ins_stc_machine_id", "=", "ins_stc_machines.id")
            ->join("users as user1", "ins_stc_d_sums.user_1_id", "=", "user1.id")
            ->select(
                "ins_stc_d_logs.*",
                "ins_stc_d_sums.*",
                "ins_stc_d_sums.id as d_sum_id",
                "ins_stc_d_sums.created_at as d_sum_created_at",
                "ins_stc_machines.line as machine_line",
                "users.emp_id as user_emp_id",
                "users.name as user_name",
                "ins_stc_d_logs.taken_at as d_log_taken_at",
                "ins_stc_d_logs.temp as d_log_temp",
            )
            ->whereBetween("ins_stc_d_sums.created_at", [$start, $end]);

        if ($this->line) {
            $query->where("ins_stc_machines.line", $this->line);
        }

        if ($this->position) {
            $query->where("ins_stc_d_sums.position", $this->position);
        }

        return $query->orderBy("ins_stc_d_sums.created_at", "DESC");
    }

    public function mount()
    {
        if (! $this->start_at || ! $this->end_at) {
            $this->setThisWeek();
        }

        $this->lines = InsStcMachine::orderBy("line")
            ->get()
            ->pluck("line")
            ->toArray();
    }

    #[On("updated")]
    public function with(): array
    {
        $dSums = $this->getDSumsQuery()->paginate($this->perPage);
        return [
            "d_sums" => $dSums,
        ];
    }

    public function loadMore()
    {
        $this->perPage += 10;
    }

    public function download($type)
    {
        switch ($type) {
            case "dsums":
                $this->js('toast("' . __("Unduhan dimulai...") . '", { type: "success" })');
                $filename = "d_sums_export_" . now()->format("Y-m-d_His") . ".csv";

                $headers = [
                    "Content-type" => "text/csv",
                    "Content-Disposition" => "attachment; filename=$filename",
                    "Pragma" => "no-cache",
                    "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
                    "Expires" => "0",
                ];

                $columns = [
                    __("Diperbarui pada"),
                    __("Line"),
                    __("Posisi"),
                    __("RPM"),
                    "HB S1",
                    "HB S2",
                    "HB S3",
                    "HB S4",
                    "HB S5",
                    "HB S6",
                    "HB S7",
                    "HB S8",
                    __("Operator") . " 1",
                    __("Operator") . " 2",
                    __("Awal"),
                    __("Durasi"),
                    __("Latensi unggah"),
                ];

                $callback = function () use ($columns) {
                    $file = fopen("php://output", "w");
                    fputcsv($file, $columns);

                    $this->getDSumsQuery()->chunk(1000, function ($dSums) use ($file) {
                        foreach ($dSums as $dSum) {
                            fputcsv($file, [
                                $dSum->d_sum_created_at,
                                $dSum->machine_line,
                                InsStc::positionHuman($dSum->position),
                                $dSum->speed,
                                $dSum->section_1,
                                $dSum->section_2,
                                $dSum->section_3,
                                $dSum->section_4,
                                $dSum->section_5,
                                $dSum->section_6,
                                $dSum->section_7,
                                $dSum->section_8,
                                $dSum->user1_name . " - " . $dSum->user1_emp_id,
                                $dSum->user2_name . " - " . $dSum->user2_emp_id,
                                $dSum->started_at,
                                $dSum->duration(),
                                $dSum->latency(),
                            ]);
                        }
                    });

                    fclose($file);
                };

                return new StreamedResponse($callback, 200, $headers);

            case "dlogs":
                $this->js('toast("' . __("Unduhan dimulai...") . '", { type: "success" })');
                $filename = "d_logs_export_" . now()->format("Y-m-d_His") . ".csv";

                $headers = [
                    "Content-type" => "text/csv",
                    "Content-Disposition" => "attachment; filename=$filename",
                    "Pragma" => "no-cache",
                    "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
                    "Expires" => "0",
                ];

                $columns = [__("ID"), __("Diperbarui pada"), __("Line"), __("Posisi"), __("RPM"), __("Operator") . " 1", __("Operator") . " 2", __("Diambil pada"), __("Suhu")];

                $callback = function () use ($columns) {
                    $file = fopen("php://output", "w");
                    fputcsv($file, $columns);

                    $this->getDLogsQuery()->chunk(1000, function ($dLogs) use ($file) {
                        foreach ($dLogs as $dLog) {
                            fputcsv($file, [
                                $dLog->d_sum_id,
                                $dLog->d_sum_created_at,
                                $dLog->machine_line,
                                InsStc::positionHuman($dLog->position),
                                $dLog->speed,
                                $dLog->user1_name . " - " . $dLog->user1_emp_id,
                                $dLog->user2_name . " - " . $dLog->user2_emp_id,
                                $dLog->d_log_taken_at,
                                $dLog->d_log_temp,
                            ]);
                        }
                    });

                    fclose($file);
                };

                return new StreamedResponse($callback, 200, $headers);
        }
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
                    <label for="device-line" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Line") }}</label>
                    <x-select class="w-full lg:w-auto" id="device-line" wire:model.live="line">
                        <option value=""></option>
                        @foreach ($lines as $line)
                            <option value="{{ $line }}">{{ $line }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div>
                    <label for="device-position" class="block px-3 mb-2 uppercase text-xs text-neutral-500">{{ __("Posisi") }}</label>
                    <x-select class="w-full lg:w-auto" id="device-position" wire:model.live="position">
                        <option value=""></option>
                        <option value="upper">{{ __("Atas") }}</option>
                        <option value="lower">{{ __("Bawah") }}</option>
                    </x-select>
                </div>
            </div>
            <div class="border-l border-neutral-300 dark:border-neutral-700 mx-2"></div>
            <div class="grow flex justify-between gap-x-2 items-center">
                <div>
                    <div class="px-3">
                        <div wire:loading.class="hidden">{{ $d_sums->total() . " " . __("ditemukan") }}</div>
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
                        <x-dropdown-link href="#" wire:click.prevent="download('dsums')">
                            <i class="icon-download me-2"></i>
                            {{ __("CSV Ringkasan") }}
                        </x-dropdown-link>
                        <x-dropdown-link href="#" wire:click.prevent="download('dlogs')">
                            <i class="icon-download me-2"></i>
                            {{ __("CSV Rinci") }}
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
        <x-modal name="d_sum-show" maxWidth="3xl">
            <livewire:insights.stc.data.d-sum-show />
        </x-modal>
    </div>
    @if (! $d_sums->count())
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
        <div wire:key="raw-d_sums" class="overflow-auto p-0 sm:p-1">
            <div class="bg-white dark:bg-neutral-800 shadow sm:rounded-lg table">
                <table class="table table-sm text-sm table-truncate text-neutral-600 dark:text-neutral-400">
                    <tr class="uppercase text-xs">
                        <th>{{ __("Line") }}</th>
                        <th>{{ __("Posisi") }}</th>
                        <th>{{ __("RPM") }}</th>
                        <th>{{ __("Penyetelan") }}</th>
                        <th>{{ __("Integritas") }}</th>
                        <th>{{ __("Dibuat pada") }}</th>
                        <th>{{ __("Waktu mulai") }}</th>
                        <th>{{ __("Durasi") }}</th>
                        <th>{{ __("Latensi") }}</th>
                        <th>{{ __("Operator") }}</th>
                    </tr>
                    @foreach ($d_sums as $d_sum)
                        <tr
                            wire:key="d_sum-tr-{{ $d_sum->id . $loop->index }}"
                            tabindex="0"
                            x-on:click="
                                $dispatch('open-modal', 'd_sum-show')
                                $dispatch('d_sum-show', { id: '{{ $d_sum->id }}' })
                            "
                        >
                            <td>{{ $d_sum->machine_line }}</td>
                            <td>{{ InsStc::positionHuman($d_sum->position) }}</td>
                            <td>{{ $d_sum->speed }}</td>
                            <td>{!! $d_sum->adjustment_friendly() !!}</td>
                            <td>{!! $d_sum->integrity_friendly() !!}</td>
                            <td>{{ $d_sum->d_sum_created_at }}</td>
                            <td>{{ $d_sum->started_at }}</td>
                            <td>
                                @php
                                    $stdDuration = is_array($d_sum->machine_std_duration) ? $d_sum->machine_std_duration : json_decode($d_sum->machine_std_duration, true);
                                    $stdMin   = $stdDuration[0] ?? null;
                                    $stdMax   = $stdDuration[1] ?? null;
                                    $duration = $d_sum->duration();
                                    $durationSeconds = null;
                                    
                                    // Parse duration string to seconds for comparison
                                    if (preg_match('/(\d+)m/', $duration, $matches)) {
                                        $durationSeconds = (int)$matches[1] * 60;
                                    } elseif (preg_match('/(\d+)s/', $duration, $matches)) {
                                        $durationSeconds = (int)$matches[1];
                                    }
                                    
                                    $isOverStd = false;
                                    if ($durationSeconds !== null && $stdMax !== null) {
                                        $isOverStd = $durationSeconds > $stdMax;
                                    }
                                @endphp
                                <span class="{{ $isOverStd ? 'text-red-600 dark:text-red-400 font-semibold' : '' }}">
                                    {{ $duration }}
                                </span>
                            </td>
                            <td>{{ $d_sum->latency() }}</td>
                            <td>
                                <div class="flex items-center">
                                    <div class="w-4 h-4 inline-block bg-neutral-200 dark:bg-neutral-700 rounded-full overflow-hidden">
                                        @if ($d_sum->user_photo ?? false)
                                            <img class="w-full h-full object-cover dark:brightness-75" src="{{ "/storage/users/" . $d_sum->user_photo }}" />
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
                                    <div class="text-sm px-2"><span>{{ $d_sum->user_name }}</span></div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
        <div class="flex items-center relative h-16">
            @if (! $d_sums->isEmpty())
                @if ($d_sums->hasMorePages())
                    <div
                        wire:key="more"
                        x-data="{
                        observe() {
                            const observer = new IntersectionObserver((d_sums) => {
                                d_sums.forEach(d_sum => {
                                    if (d_sum.isIntersecting) {
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
