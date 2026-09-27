<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollCompletion extends Model
{
    protected $fillable = ['attendance_period_id', 'pt', 'completed_by'];

    public function period(): BelongsTo
    {
        return $this->belongsTo(AttendancePeriod::class, 'attendance_period_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function loanFinalizations(): HasMany
    {
        return $this->hasMany(PayrollCompletionLoan::class);
    }

    public function salarySlipSnapshots(): HasMany
    {
        return $this->hasMany(PayrollSalarySlipSnapshot::class);
    }
}
