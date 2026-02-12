<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsPhDosingAuth extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'actions',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
