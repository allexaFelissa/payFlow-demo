<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtraTimeDetail extends Model
{
    protected $fillable = ['extra_time_id', 'attendance_date', 'is_holiday', 'amount', 'notes'];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'is_holiday' => 'boolean',
            'amount' => 'decimal:2',
        ];
    }

    public function extraTime(): BelongsTo
    {
        return $this->belongsTo(ExtraTime::class);
    }
}
