<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Overtime extends Model
{
    protected $fillable = ['employee_id', 'payroll_period_id', 'hours', 'hourly_rate', 'amount', 'notes'];

    protected function casts(): array
    {
        return ['hours' => 'decimal:2', 'hourly_rate' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(AttendancePeriod::class, 'payroll_period_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(OvertimeDetail::class);
    }
}
