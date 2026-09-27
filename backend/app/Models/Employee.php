<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_number', 'name', 'email', 'phone', 'gender', 'birth_date', 'address',
        'emergency_contact_name', 'emergency_contact_phone', 'department', 'position', 'branch', 'pts', 'family_status',
        'bank_name', 'bank_account_number', 'tax_number', 'employment_status', 'join_date', 'active',
        'loan_amount', 'loan_balance', 'loan_installment', 'loan_start_date', 'loan_notes',
    ];

    protected function casts(): array
    {
        return [
            'join_date' => 'date', 'birth_date' => 'date', 'loan_start_date' => 'date',
            'active' => 'boolean', 'loan_amount' => 'decimal:2', 'loan_balance' => 'decimal:2',
            'loan_installment' => 'decimal:2', 'pts' => 'array',
        ];
    }

    public function scopeAssignedToPt(Builder $query, string $pt): Builder
    {
        return $query->where(function (Builder $query) use ($pt) {
            $query->whereJsonContains('pts', $pt)
                ->orWhere(function (Builder $legacy) use ($pt) {
                    $legacy->where(function (Builder $missingPts) {
                        $missingPts->whereNull('pts')->orWhereJsonLength('pts', 0);
                    })->where('branch', $pt);
                });
        });
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function attendancePeriodEmployee(): HasMany
    {
        return $this->hasMany(AttendancePeriodEmployee::class);
    }

    public function payrollBaseline(): HasOne
    {
        return $this->hasOne(EmployeePayrollBaseline::class);
    }

    public function payrollBaselines(): HasMany
    {
        return $this->hasMany(EmployeePayrollBaseline::class);
    }

    public function assignedPts(): array
    {
        $pts = is_array($this->pts) ? $this->pts : [];
        if ($pts === [] && $this->branch) {
            $pts = [$this->branch];
        }

        return array_values(array_unique(array_filter($pts)));
    }

    public function primaryPayrollPt(): ?string
    {
        $pts = $this->assignedPts();
        if ($this->branch && in_array($this->branch, $pts, true)) {
            return $this->branch;
        }

        return $pts[0] ?? null;
    }

    public function payrollBaselineForPt(?string $pt): ?EmployeePayrollBaseline
    {
        if (! $pt) {
            return null;
        }
        if ($this->relationLoaded('payrollBaselines')) {
            return $this->payrollBaselines->firstWhere('pt', $pt);
        }

        return $this->payrollBaselines()->where('pt', $pt)->first();
    }

    public function payrollCalculations(): HasMany
    {
        return $this->hasMany(PayrollCalculation::class);
    }

    public function overtimes(): HasMany
    {
        return $this->hasMany(Overtime::class);
    }

    public function extraTimes(): HasMany
    {
        return $this->hasMany(ExtraTime::class);
    }
}
