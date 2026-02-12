<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectWorkingHour extends Model
{
    protected $fillable = [
        'project_group',
        'shift_id',
        'work_start_time',
        'work_end_time',
        'is_working_day',
        'break_times',
    ];

    protected $casts = [
        'is_working_day' => 'boolean',
        'break_times' => 'array',
    ];

    /**
     * Get the shift that owns the working hour
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Scope a query to only include working days
     */
    public function scopeWorkingDays($query)
    {
        return $query->where('is_working_day', true);
    }

    /**
     * Scope a query for a specific project group
     */
    public function scopeForProjectGroup($query, string $projectGroup)
    {
        return $query->where('project_group', $projectGroup);
    }

    /**
     * Scope a query for a specific shift
     */
    public function scopeForShift($query, int $shiftId)
    {
        return $query->where('shift_id', $shiftId);
    }
}
