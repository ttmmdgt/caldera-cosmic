<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsRubberColor extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
    ];

    public function ins_rubber_batches(): HasMany
    {
        return $this->hasMany(InsRubberBatch::class);
    }
}
