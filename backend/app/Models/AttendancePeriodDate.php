<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendancePeriodDate extends Model
{
    protected $fillable = ['attendance_period_id', 'attendance_date'];

    protected function casts(): array
    {
        return ['attendance_date' => 'date'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AttendancePeriod::class, 'attendance_period_id');
    }
}
