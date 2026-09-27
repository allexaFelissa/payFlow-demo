<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OvertimeDetail extends Model
{
    protected $fillable = ['overtime_id', 'attendance_date', 'starts_at', 'ends_at', 'duration_minutes', 'is_holiday'];

    protected function casts(): array
    {
        return ['attendance_date' => 'date', 'is_holiday' => 'boolean'];
    }

    public function overtime(): BelongsTo
    {
        return $this->belongsTo(Overtime::class);
    }
}
