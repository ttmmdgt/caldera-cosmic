<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogDwpUptime extends Model
{
    use HasFactory;

    protected $table = 'log_dwp_uptime';

    protected $fillable = [
        'ins_dwp_device_id',
        'status',
        'logged_at',
        'message',
        'duration_seconds',
    ];

    protected $casts = [
        'logged_at' => 'datetime',
        'duration_seconds' => 'integer',
    ];

    /**
     * Get the device that owns this log entry
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(InsDwpDevice::class, 'ins_dwp_device_id');
    }

    /**
     * Scope to filter by status
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by device
     */
    public function scopeForDevice($query, int $deviceId)
    {
        return $query->where('ins_dwp_device_id', $deviceId);
    }

    /**
     * Scope to get recent logs
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('logged_at', '>=', now()->subHours($hours));
    }

    /**
     * Get the latest status for a device
     */
    public static function getLatestStatus(int $deviceId): ?string
    {
        $log = static::forDevice($deviceId)
            ->orderBy('logged_at', 'desc')
            ->first();
        
        return $log?->status;
    }

    /**
     * Calculate uptime percentage for a device
     */
    public static function calculateUptime(int $deviceId, int $hours = 24): float
    {
        $logs = static::forDevice($deviceId)
            ->recent($hours)
            ->orderBy('logged_at', 'asc')
            ->get();

        if ($logs->isEmpty()) {
            return 0;
        }

        $totalSeconds = $hours * 3600;
        $offlineSeconds = 0;

        foreach ($logs as $log) {
            if ($log->status === 'offline' && $log->duration_seconds) {
                $offlineSeconds += $log->duration_seconds;
            }
        }

        return max(0, min(100, (($totalSeconds - $offlineSeconds) / $totalSeconds) * 100));
    }
}
