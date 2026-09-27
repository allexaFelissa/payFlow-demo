<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollLoanDetail extends Model
{
    protected $fillable = ['payroll_calculation_id', 'employee_id', 'loan_amount', 'installment_amount', 'deducted_amount', 'remaining_before', 'remaining_after', 'loan_start_date', 'loan_notes'];

    protected function casts(): array
    {
        return [
            'loan_amount' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'deducted_amount' => 'decimal:2',
            'remaining_before' => 'decimal:2',
            'remaining_after' => 'decimal:2',
            'loan_start_date' => 'date',
        ];
    }

    public function payrollCalculation(): BelongsTo
    {
        return $this->belongsTo(PayrollCalculation::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
