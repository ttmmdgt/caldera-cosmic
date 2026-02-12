<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InsPhDosingCount extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'ph_value',
    ];

    protected $casts = [
        'ph_value' => 'array',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(InsPhDosingDevice::class);
    }
}
