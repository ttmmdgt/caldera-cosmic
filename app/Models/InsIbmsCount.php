<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsIbmsCount extends Model
{
    protected $table = 'ins_ip_blend_counts';

    protected $fillable = [
        'shift',
        'duration',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];
}
