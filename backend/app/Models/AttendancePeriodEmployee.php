<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendancePeriodEmployee extends Model
{
    protected $fillable = ['attendance_period_id', 'employee_id', 'monthly_overtime_hours'];

    protected function casts(): array
    {
        return ['monthly_overtime_hours' => 'decimal:2'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
