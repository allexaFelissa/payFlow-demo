<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollSalarySlipSnapshot extends Model
{
    protected $fillable = [
        'payroll_completion_id',
        'payroll_calculation_id',
        'attendance_period_id',
        'pt',
        'employee_number',
        'employee_name',
        'payload',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function completion(): BelongsTo
    {
        return $this->belongsTo(PayrollCompletion::class, 'payroll_completion_id');
    }

    public function calculation(): BelongsTo
    {
        return $this->belongsTo(PayrollCalculation::class, 'payroll_calculation_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AttendancePeriod::class, 'attendance_period_id');
    }
}
