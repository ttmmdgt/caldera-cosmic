<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class UptimeLog extends Model
{
    protected $fillable = [
        'project_name',
        'ip_address',
        'status',
        'previous_status',
        'message',
        'duration',
        'checked_at',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'duration' => 'integer',
    ];

    /**
     * Scope untuk filter berdasarkan status
     */
    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope untuk filter berdasarkan project
     */
    public function scopeProject($query, $projectName)
    {
        return $query->where('project_name', $projectName);
    }

    /**
     * Scope untuk mendapatkan log hari ini
     */
    public function scopeToday($query)
    {
        return $query->whereDate('checked_at', Carbon::today());
    }

    /**
     * Scope untuk mendapatkan log dalam range waktu
     */
    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('checked_at', [$startDate, $endDate]);
    }

    /**
     * Get latest status for each project
     */
    public static function getLatestStatusByProject()
    {
        return self::select('uptime_logs.*')
            ->whereIn('id', function($query) {
                $query->selectRaw('MAX(id)')
                    ->from('uptime_logs')
                    ->groupBy('project_name');
            })
            ->orderBy('project_name')
            ->get();
    }

    /**
     * Get uptime percentage for a project in a date range
     */
    public static function getUptimePercentage($projectName, $startDate = null, $endDate = null)
    {
        $query = self::where('project_name', $projectName);
        
        if ($startDate && $endDate) {
            $query->whereBetween('checked_at', [$startDate, $endDate]);
        }

        $total = $query->count();
        $online = $query->where('status', 'online')->count();

        if ($total === 0) {
            return 0;
        }

        return round(($online / $total) * 100, 2);
    }

    /**
     * Format duration untuk human readable
     */
    public function getFormattedDurationAttribute()
    {
        if (!$this->duration) {
            return '-';
        }

        $seconds = $this->duration;
        
        if ($seconds < 60) {
            return $seconds . 's';
        } elseif ($seconds < 3600) {
            return round($seconds / 60, 1) . 'm';
        } elseif ($seconds < 86400) {
            return round($seconds / 3600, 1) . 'h';
        } else {
            return round($seconds / 86400, 1) . 'd';
        }
    }

    /**
     * Get status badge color
     */
    public function getStatusColorAttribute()
    {
        return match($this->status) {
            'online' => 'green',
            'offline' => 'red',
            'idle' => 'yellow',
            default => 'gray'
        };
    }
}
