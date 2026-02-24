<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsIbmsAuth extends Model
{
    protected $table = 'ins_ip_blend_auths';

    protected $fillable = [
        'user_id',
        'actions',
    ];

    protected $casts = [
        'actions' => 'array',
    ];
}
