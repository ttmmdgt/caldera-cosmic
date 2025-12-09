<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsDwpLoadcell extends Model
{
    use HasFactory;

    protected $table = 'ins_dwp_loadcell';

    protected $fillable = [
        'machine_name',
        'plant',
        'line',
        'duration',
        'position',
        'range_std',
        'toe_heel',
        'side',
        'result',
        'operator',
        'recorded_at',
        'loadcell_data',
    ];

    protected $casts = [
        'cumulative' => 'integer',
        'incremental' => 'integer',
    ];

    /**
     * Boot the model to normalize line names
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($count) {
            $count->line = strtoupper(trim($count->line));
        });
    }

    /**
     * Get the device that manages this line
     */
    public function device(): ?InsDwpDevice
    {
        return InsDwpDevice::active()
            ->get()
            ->first(function ($device) {
                return $device->managesLine($this->line);
            });
    }

    /**
     * Get counts for a specific line and date range
     */
    public static function forLineBetween(string $line, Carbon $from, Carbon $to)
    {
        return static::where('line', strtoupper(trim($line)))
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Get daily summary for a line in a date range
     */
    public static function dailySummaryForLine(string $line, Carbon $from, Carbon $to): array
    {
        $line = strtoupper(trim($line));
        
        return static::where('line', $line)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('
                DATE(created_at) as date,
                SUM(incremental) as total_incremental,
                MAX(cumulative) as max_cumulative,
                MIN(cumulative) as min_cumulative,
                COUNT(*) as count
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'total_incremental' => (int) $item->total_incremental,
                    'cumulative_change' => (int) ($item->max_cumulative - $item->min_cumulative),
                    'count' => (int) $item->count,
                ];
            })
            ->toArray();
    }

    /**
     * Get summary for all lines in a date range
     */
    public static function summaryBetween(Carbon $from, Carbon $to): array
    {
        return static::whereBetween('created_at', [$from, $to])
            ->selectRaw('
                line,
                SUM(incremental) as total_incremental,
                MAX(cumulative) as latest_cumulative,
                COUNT(*) as count
            ')
            ->groupBy('line')
            ->orderBy('line')
            ->get()
            ->map(function ($item) {
                return [
                    'line' => $item->line,
                    'total_incremental' => (int) $item->total_incremental,
                    'latest_cumulative' => (int) $item->latest_cumulative,
                    'count' => (int) $item->count,
                ];
            })
            ->toArray();
    }

    /**
     * Get the latest count for a specific line
     */
    public static function latestForLine(string $line): ?static
    {
        return static::where('line', strtoupper(trim($line)))
            ->latest('created_at')
            ->first();
    }

    /**
     * Get counts for today for a specific line
     */
    public static function todayForLine(string $line)
    {
        return static::forLineBetween(
            $line,
            Carbon::today(),
            Carbon::tomorrow()
        );
    }

    /**
     * Get counts for current week for all lines
     */
    public static function currentWeekSummary(): array
    {
        return static::summaryBetween(
            Carbon::now()->startOfWeek(),
            Carbon::now()->endOfWeek()
        );
    }

    /**
     * Scope for specific line
     */
    public function scopeForLine($query, string $line)
    {
        return $query->where('line', strtoupper(trim($line)));
    }

    /**
     * Scope for date range
     */
    public function scopeBetweenDates($query, Carbon $from, Carbon $to)
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    // update long duration 
}
