<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollManualAdjustment extends Model
{
    protected $fillable = ['payroll_calculation_id', 'employee_id', 'payroll_period_id', 'type', 'component_name', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function payrollCalculation(): BelongsTo
    {
        return $this->belongsTo(PayrollCalculation::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(AttendancePeriod::class, 'payroll_period_id');
    }
}
