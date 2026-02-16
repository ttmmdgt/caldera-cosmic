<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InsPhDosingLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'dosing_amount',
        'data_dosing',
    ];

    protected $casts = [
        'dosing_amount' => 'integer',
        'data_dosing' => 'json',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(InsPhDosingDevice::class);
    }
}
