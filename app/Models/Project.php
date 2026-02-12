<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        'name',
        'project_group',
        'ip',
        'timeout',
        'type',
        'modbus_config',
        'is_active',
        'location',
        'description',
    ];

    protected $casts = [
        'modbus_config' => 'array',
        'is_active' => 'boolean',
        'timeout' => 'integer',
    ];

    /**
     * Get the working hours for this project's group
     */
    public function workingHours(): HasMany
    {
        return $this->hasMany(ProjectWorkingHour::class, 'project_group', 'project_group');
    }

    /**
     * Get active working hours for this project's group
     */
    public function activeWorkingHours(): HasMany
    {
        return $this->hasMany(ProjectWorkingHour::class, 'project_group', 'project_group')
            ->where('is_working_day', true);
    }

    /**
     * Scope a query to only include active projects
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to filter by project group
     */
    public function scopeByGroup($query, string $group)
    {
        return $query->where('project_group', $group);
    }

    /**
     * Scope a query to filter by type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
