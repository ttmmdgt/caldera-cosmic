<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsRdcTest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'ins_rubber_batch_id',
        'eval',
        's_min',
        's_max',
        'tc10',
        'tc50',
        'tc90',
        'data',
        'queued_at',
        'ins_rdc_machine_id',
        's_min_low',
        's_min_high',
        's_max_low',
        's_max_high',
        'tc10_low',
        'tc10_high',
        'tc50_low',
        'tc50_high',
        'tc90_low',
        'tc90_high',
        'type',
        'shift',
    ];

    public function evalHuman(): string
    {
        $this->eval;

        switch ($this->eval) {
            case 'pass':
                return __('Pass');

            case 'fail':
                return __('Fail');

        }

        return 'N/A';
    }

    public function ins_rubber_batch(): BelongsTo
    {
        return $this->belongsTo(InsRubberBatch::class);
    }

    public function ins_rdc_machine(): BelongsTo
    {
        return $this->belongsTo(InsRdcMachine::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
