<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InsStcMachine extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'line',
        'ip_address',
        'is_at_adjusted',
        'at_adjust_strength',
        'section_limits_high',
        'section_limits_low',
        'std_duration'
    ];

    protected $casts = [
        'line' => 'integer',
        'is_at_adjusted' => 'boolean',
        'at_adjust_strength' => 'array',
        'section_limits_high' => 'array',
        'section_limits_low' => 'array',
        'std_duration' => 'array',
    ];

    public function ins_stc_m_logs(): HasMany
    {
        return $this->hasMany(InsStcMLog::class);
    }

    public function ins_stc_d_sums(): HasMany
    {
        return $this->hasMany(InsStcDSum::class);
    }

    public function ins_stc_m_log($position): HasOne
    {
        return $this->hasOne(InsStcMLog::class)
            ->latest()
            ->where('position', $position)
            ->where('created_at', '>=', now()->subHour());
    }

    /**
     * Get section high limits with fallback to default values
     */
    public function getSectionLimitsHighAttribute($value)
    {
        $limits = $this->castAttribute('section_limits_high', $value);

        // Fallback to default values if not set or invalid
        if (! is_array($limits) || count($limits) !== 8) {
            return [83, 78, 73, 68, 63, 58, 53, 48];
        }

        return $limits;
    }

    /**
     * Get section low limits with fallback to default values
     */
    public function getSectionLimitsLowAttribute($value)
    {
        $limits = $this->castAttribute('section_limits_low', $value);

        // Fallback to default values if not set or invalid
        if (! is_array($limits) || count($limits) !== 8) {
            return [73, 68, 63, 58, 53, 48, 43, 38];
        }

        return $limits;
    }
}
