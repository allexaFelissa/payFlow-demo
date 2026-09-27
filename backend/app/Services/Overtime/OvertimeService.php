<?php

namespace App\Services\Overtime;

use App\Models\AttendancePeriod;
use App\Models\Employee;
use App\Models\ExtraTime;
use App\Models\Overtime;
use App\Models\PayrollBaseline;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OvertimeService
{
    private const PRESENT_STATUSES = ['M', 'L'];

    public function __construct(
        private readonly OvertimeRateConverter $rateConverter,
        private readonly OvertimeAmountCalculator $amountCalculator,
        private readonly ExtraTimeService $extraTime
    ) {}

    public function rows(AttendancePeriod $period, array $filters = []): LengthAwarePaginator
    {
        $employees = $this->presentEmployees($period, $filters)
            ->with('payrollBaselines')->orderBy('name')->paginate(20);
        $employeeIds = $employees->getCollection()->pluck('id');
        $overtimeByEmployee = Overtime::query()->where('payroll_period_id', $period->id)
            ->whereIn('employee_id', $employeeIds)->with('details')->get()->keyBy('employee_id');
        $extraTimeByEmployee = ExtraTime::query()->where('payroll_period_id', $period->id)
            ->whereIn('employee_id', $employeeIds)->get()->keyBy('employee_id');
        $configuration = PayrollBaseline::active()->latest('id')->first();

        $employees->setCollection($employees->getCollection()
            ->map(function (Employee $employee) use ($period, $overtimeByEmployee, $extraTimeByEmployee, $configuration, $filters) {
                $selectedPt = $filters['branch'] ?? $employee->primaryPayrollPt();
                $employee->setRelation('payrollBaseline', $employee->payrollBaselineForPt($selectedPt));
                if ($selectedPt) {
                    $employee->setAttribute('branch', $selectedPt);
                }
                $overtime = $overtimeByEmployee->get($employee->id);
                if ($overtime) {
                    $overtime->hours = $this->extraTime->eligible($employee)
                        ? 0
                        : $this->totalRateHours($overtime->details);
                    if ($this->extraTime->eligible($employee)) {
                        $overtime->amount = 0;
                    } elseif ($configuration) {
                        $overtime->hourly_rate = (float) $configuration->lembur_hari_kerja_rate;
                        $overtime->amount = $this->amountForDetails($overtime->details, (float) ($employee->payrollBaseline?->uang_harian ?? 0), $configuration);
                    }
                    $overtime->setRelation('employee', $employee);
                    $this->setExtraTimeAttributes($overtime, $employee, $extraTimeByEmployee->get($employee->id));

                    return $overtime;
                }

                $overtime = new Overtime(['employee_id' => $employee->id, 'payroll_period_id' => $period->id, 'hours' => 0, 'hourly_rate' => $this->defaultHourlyRate($configuration), 'amount' => 0]);
                $overtime->setRelation('employee', $employee);
                $this->setExtraTimeAttributes($overtime, $employee, $extraTimeByEmployee->get($employee->id));

                return $overtime;
            }));

        return $employees;
    }

    public function details(AttendancePeriod $period, Employee $employee): array
    {
        $this->ensureOvertimeEligible($employee);

        $attendance = $employee->attendanceRecords()->where('attendance_period_id', $period->id)
            ->whereIn('status', self::PRESENT_STATUSES)->orderBy('attendance_date')->get();
        $overtime = Overtime::query()->where(['employee_id' => $employee->id, 'payroll_period_id' => $period->id])->with('details')->first();
        $details = $overtime?->details->keyBy(fn ($detail) => $detail->attendance_date->toDateString()) ?? collect();
        $dates = $attendance->pluck('attendance_date')->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->merge($details->keys())->unique()->sort()->values();
        if ($dates->isEmpty()) {
            throw ValidationException::withMessages(['employee_id' => 'Karyawan tidak memiliki data hadir pada periode ini.']);
        }

        return $dates->map(function ($date) use ($details) {
            $detail = $details->get($date);
            $minutes = $detail?->duration_minutes ?? 0;
            $rateHours = $this->rateConverter->fromDurationMinutes($minutes);

            return [
                'date' => $date,
                'is_holiday' => $detail?->is_holiday ?? false,
                'starts_at' => $detail?->starts_at ? substr($detail->starts_at, 0, 5) : null,
                'ends_at' => $detail?->ends_at ? substr($detail->ends_at, 0, 5) : null,
                'duration_minutes' => $minutes,
                'duration_label' => $minutes ? intdiv($minutes, 60).' jam '.($minutes % 60).' menit' : '0 jam',
                'rate_hours' => $rateHours,
                'rate_label' => number_format($rateHours, 2, '.', ''),
            ];
        })->values()->all();
    }

    public function calculationRates(Employee $employee): array
    {
        $configuration = PayrollBaseline::active()->latest('id')->first();
        $employee = $this->withPrimaryBaseline($employee);

        return [
            'workday_rate' => (float) ($configuration?->lembur_hari_kerja_rate ?? 0),
            'holiday_multiplier' => (float) ($configuration?->lembur_hari_libur_multiplier ?? 0),
            'daily_rate' => (float) ($employee->payrollBaseline?->uang_harian ?? 0),
        ];
    }

    public function saveDetails(AttendancePeriod $period, Employee $employee, array $rows): Overtime
    {
        $this->ensureOvertimeEligible($employee);

        $validDates = $employee->attendanceRecords()->where('attendance_period_id', $period->id)->whereIn('status', self::PRESENT_STATUSES)
            ->pluck('attendance_date')->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->merge(Overtime::query()->where(['employee_id' => $employee->id, 'payroll_period_id' => $period->id])->first()?->details()->pluck('attendance_date') ?? collect())
            ->map(fn ($date) => Carbon::parse($date)->toDateString())->unique()->all();
        $invalidDates = collect($rows)->pluck('date')->diff($validDates);
        if ($invalidDates->isNotEmpty()) {
            throw ValidationException::withMessages(['rows' => 'Lembur hanya dapat dicatat pada tanggal kehadiran.']);
        }

        return DB::transaction(function () use ($period, $employee, $rows) {
            $configuration = PayrollBaseline::active()->latest('id')->first();
            $overtime = Overtime::firstOrCreate(
                ['employee_id' => $employee->id, 'payroll_period_id' => $period->id],
                ['hours' => 0, 'hourly_rate' => $this->defaultHourlyRate($configuration), 'amount' => 0],
            );

            foreach ($rows as $row) {
                $start = $row['starts_at'] ?? null;
                $end = $row['ends_at'] ?? null;
                if ($this->isEmptyOvertimeTime($start) && $this->isEmptyOvertimeTime($end)) {
                    $start = null;
                    $end = null;
                }
                if (! $start && ! $end) {
                    $overtime->details()->updateOrCreate(
                        ['attendance_date' => $row['date']],
                        ['starts_at' => null, 'ends_at' => null, 'duration_minutes' => 0, 'is_holiday' => $row['is_holiday'] ?? false],
                    );

                    continue;
                }
                if (! $start || ! $end) {
                    throw ValidationException::withMessages(['rows' => 'Jam mulai dan jam selesai harus diisi bersamaan.']);
                }
                $startedAt = Carbon::createFromFormat('H:i', $start);
                $endedAt = Carbon::createFromFormat('H:i', $end);
                if ($endedAt->equalTo($startedAt)) {
                    throw ValidationException::withMessages(['rows' => 'Jam mulai dan jam selesai tidak boleh sama.']);
                }
                if ($endedAt->lessThan($startedAt)) {
                    $endedAt->addDay();
                }
                $minutes = $startedAt->diffInMinutes($endedAt);
                $overtime->details()->updateOrCreate(['attendance_date' => $row['date']], ['starts_at' => $start, 'ends_at' => $end, 'duration_minutes' => $minutes, 'is_holiday' => $row['is_holiday'] ?? false]);
            }

            $details = $overtime->details()->get();
            $hours = $this->totalRateHours($details);
            $employee = $this->withPrimaryBaseline($employee);
            $rate = (float) ($configuration?->lembur_hari_kerja_rate ?? 0);
            $amount = $configuration ? $this->amountForDetails($details, (float) ($employee->payrollBaseline?->uang_harian ?? 0), $configuration) : 0;
            $overtime->update(['hours' => $hours, 'hourly_rate' => $rate, 'amount' => $amount]);

            return $overtime->refresh()->load('employee');
        });
    }

    public function save(AttendancePeriod $period, array $rows): LengthAwarePaginator
    {
        return DB::transaction(function () use ($period, $rows) {
            $employees = Employee::query()
                ->whereIn('id', collect($rows)->pluck('employee_id')->unique())
                ->get()
                ->keyBy('id');

            foreach ($rows as $row) {
                $employee = $employees->get($row['employee_id']);
                if ($employee) {
                    $this->ensureOvertimeEligible($employee);
                }
                $hours = (int) $row['hours'];
                $rate = round((float) $row['hourly_rate'], 2);
                Overtime::updateOrCreate(['employee_id' => $row['employee_id'], 'payroll_period_id' => $period->id], ['hours' => $hours, 'hourly_rate' => $rate, 'amount' => round($hours * $rate, 2), 'notes' => $row['notes'] ?? null]);
            }

            return $this->rows($period);
        });
    }

    public function update(Overtime $overtime, array $data): Overtime
    {
        $this->ensureOvertimeEligible($overtime->employee()->withTrashed()->firstOrFail());

        $hours = (int) ($data['hours'] ?? $overtime->hours);
        $rate = round((float) ($data['hourly_rate'] ?? $overtime->hourly_rate), 2);
        $overtime->update(['hours' => $hours, 'hourly_rate' => $rate, 'amount' => round($hours * $rate, 2), 'notes' => $data['notes'] ?? $overtime->notes]);

        return $overtime->refresh()->load('employee');
    }

    public function delete(Overtime $overtime): void
    {
        $overtime->delete();
    }

    public function branches(AttendancePeriod $period): array
    {
        return Cache::remember("overtime:branches:{$period->id}", now()->addMinute(), fn () => $this->presentEmployees($period)->get()
                ->flatMap(fn (Employee $employee) => $employee->assignedPts())
                ->unique()
                ->sort()
                ->values()
                ->all());
    }

    private function presentEmployees(AttendancePeriod $period, array $filters = [])
    {
        return Employee::query()->where('active', true)->where(function ($query) use ($period) {
            $query->whereHas('attendanceRecords', fn ($attendance) => $attendance->where('attendance_period_id', $period->id)->whereIn('status', self::PRESENT_STATUSES))
                ->orWhereHas('overtimes', fn ($overtime) => $overtime->where('payroll_period_id', $period->id));
        })
            ->when($filters['branch'] ?? null, fn ($query, $branch) => $query->assignedToPt($branch))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($q) => $q->whereLike('name', '%'.$search.'%')->orWhereLike('employee_number', '%'.$search.'%')));
    }

    private function defaultHourlyRate(?PayrollBaseline $configuration): float
    {
        return round((float) ($configuration?->lembur_hari_kerja_rate ?? 0), 2);
    }

    private function withPrimaryBaseline(Employee $employee): Employee
    {
        $employee->loadMissing('payrollBaselines');
        $employee->setRelation(
            'payrollBaseline',
            $employee->payrollBaselineForPt($employee->primaryPayrollPt())
        );

        return $employee;
    }

    private function isEmptyOvertimeTime(mixed $value): bool
    {
        return in_array($value, [null, '', 0, '0', '00:00', '00:00:00'], true);
    }

    private function totalRateHours(iterable $details): float
    {
        $total = 0.0;
        foreach ($details as $detail) {
            $total += $this->rateConverter->fromDurationMinutes((int) $detail->duration_minutes);
        }

        return round($total, 2);
    }

    private function amountForDetails(iterable $details, float $dailyRate, PayrollBaseline $configuration): float
    {
        return $this->amountCalculator->calculate($details, $dailyRate, $configuration)['amount'];
    }

    private function setExtraTimeAttributes(Overtime $overtime, Employee $employee, ?ExtraTime $extraTime): void
    {
        $extraTimeEligible = $this->extraTime->eligible($employee);
        $overtime->setAttribute('overtime_eligible', ! $extraTimeEligible);
        $overtime->setAttribute('extra_time_eligible', $extraTimeEligible);
        $overtime->setAttribute('extra_time_amount', (float) ($extraTime?->amount ?? 0));
        if ($extraTimeEligible && $extraTime?->updated_at) {
            $overtime->updated_at = $extraTime->updated_at;
        }
    }

    private function ensureOvertimeEligible(Employee $employee): void
    {
        if ($this->extraTime->eligible($employee)) {
            throw ValidationException::withMessages([
                'employee_id' => 'Lembur hanya tersedia untuk karyawan non-Koordinator. Gunakan Extra Time untuk karyawan Koordinator.',
            ]);
        }
    }
}
