<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExtraTime extends Model
{
    protected $fillable = ['employee_id', 'payroll_period_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
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
        return $this->hasMany(ExtraTimeDetail::class);
    }
}
