<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InsPhDosingDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'plant',
        'ip_address',
        'config',
        'is_active',
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
    ];

    public function counts(): HasMany
    {
        return $this->hasMany(InsPhDosingCount::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(InsPhDosingLog::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
