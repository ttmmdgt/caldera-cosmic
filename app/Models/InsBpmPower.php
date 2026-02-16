<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsBpmPower extends Model
{
    protected $table = 'ins_bpm_count_power';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'device_id',
        'machine',
        'condition',
        'incremental',
        'cumulative',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'incremental' => 'integer',
        'cumulative' => 'integer',
    ];

    /**
     * Get the device that owns this power record
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(InsBpmDevice::class);
    }

    /**
     * Get the line from the related device
     */
    public function getLine(): ?string
    {
        return $this->device?->line;
    }

    /**
     * Get the machines from the related device config
     */
    public function getMachines(): array
    {
        return $this->device?->getMachines() ?? [];
    }
}
