<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsBpmCount extends Model
{
    protected $fillable = [
        'incremental',
        'cumulative',
        'plant',
        'line',
        'machine',
        'condition',
    ];

    protected $casts = [
        'incremental' => 'integer',
        'cumulative' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Boot the model to normalize line and machine names
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($count) {
            $count->line = strtoupper(trim($count->line));
            $count->machine = strtoupper(trim($count->machine));
        });
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
}

