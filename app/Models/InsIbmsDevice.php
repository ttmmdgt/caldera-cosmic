<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsIbmsDevice extends Model
{
    protected $table = 'ins_ip_blend_devices';

    protected $fillable = [
        'name',
        'ip_address',
        'config',
        'is_active',
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
    ];
}
