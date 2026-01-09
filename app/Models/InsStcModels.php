<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsStcModels extends Model
{
    protected $fillable = [
        'name',
        'std_duration',
        'std_temperature',
        'status',
    ];

    protected $casts = [
        'std_duration' => 'array',
        'std_temperature' => 'array',
    ];

    protected $attributes = [
        'status' => 'active',
    ];
}
