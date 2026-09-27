<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollCompletionLoan extends Model
{
    protected $fillable = ['payroll_completion_id', 'employee_id', 'deduction_amount', 'loan_amount_before', 'loan_balance_before', 'loan_installment_before', 'loan_start_date_before', 'loan_notes_before'];

    protected function casts(): array
    {
        return ['deduction_amount' => 'decimal:2', 'loan_amount_before' => 'decimal:2', 'loan_balance_before' => 'decimal:2', 'loan_installment_before' => 'decimal:2', 'loan_start_date_before' => 'date'];
    }

    public function completion(): BelongsTo
    {
        return $this->belongsTo(PayrollCompletion::class, 'payroll_completion_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
