<?php

namespace App\Models;

use App\Enums\AttendancePeriodStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendancePeriod extends Model
{
    protected $fillable = ['month', 'year', 'starts_on', 'ends_on', 'status', 'created_by', 'imported_at'];

    protected function casts(): array
    {
        return ['status' => AttendancePeriodStatus::class, 'starts_on' => 'date', 'ends_on' => 'date', 'imported_at' => 'datetime'];
    }

    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function dates(): HasMany
    {
        return $this->hasMany(AttendancePeriodDate::class);
    }

    public function employeePeriods(): HasMany
    {
        return $this->hasMany(AttendancePeriodEmployee::class);
    }

    public function overtimes(): HasMany
    {
        return $this->hasMany(Overtime::class, 'payroll_period_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
