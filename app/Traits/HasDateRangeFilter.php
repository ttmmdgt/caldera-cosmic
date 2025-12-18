<?php

namespace App\Traits;

use Carbon\Carbon;

trait HasDateRangeFilter
{
    public function setToday()
    {
        $this->start_at = Carbon::now()->startOfDay()->format('Y-m-d');
        $this->end_at = Carbon::now()->endOfDay()->format('Y-m-d');
        $this->dispatch('update');
    }

    public function setYesterday()
    {
        $this->start_at = Carbon::yesterday()->startOfDay()->format('Y-m-d');
        $this->end_at = Carbon::yesterday()->endOfDay()->format('Y-m-d');
        $this->dispatch('update');
    }

    public function setThisWeek()
    {
        $this->start_at = Carbon::now()->startOfWeek()->format('Y-m-d');
        $this->end_at = Carbon::now()->endOfWeek()->format('Y-m-d');
        $this->dispatch('update');
    }

    public function setLastWeek()
    {
        $this->start_at = Carbon::now()->subWeek()->startOfWeek()->format('Y-m-d');
        $this->end_at = Carbon::now()->subWeek()->endOfWeek()->format('Y-m-d');
        $this->dispatch('update');
    }

    public function setThisMonth()
    {
        $this->start_at = Carbon::now()->startOfMonth()->format('Y-m-d');
        $this->end_at = Carbon::now()->endOfMonth()->format('Y-m-d');
        $this->dispatch('update');
    }

    public function setLastMonth()
    {
        $this->start_at = Carbon::now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d');
        $this->end_at = Carbon::now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d');
        $this->dispatch('update');
    }

    public function setThisQuarter()
    {
        $this->start_at = Carbon::now()->startOfQuarter()->format('Y-m-d');
        $this->end_at = Carbon::now()->endOfQuarter()->format('Y-m-d');
        $this->dispatch('update');
    }

    public function setLastQuarter()
    {
        $this->start_at = Carbon::now()->subQuarter()->startOfQuarter()->format('Y-m-d');
        $this->end_at = Carbon::now()->subQuarter()->endOfQuarter()->format('Y-m-d');
        $this->dispatch('update');
    }
}
