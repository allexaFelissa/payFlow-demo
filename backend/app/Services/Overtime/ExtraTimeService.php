<?php

namespace App\Services\Overtime;

use App\Models\AttendancePeriod;
use App\Models\Employee;
use App\Models\ExtraTime;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExtraTimeService
{
    private const WORKDAY_AMOUNT = 25000;

    public function eligible(Employee $employee): bool
    {
        $position = Str::lower(Str::ascii((string) $employee->position));

        return Str::contains($position, ['koordinator', 'coordinator']);
    }

    public function details(AttendancePeriod $period, Employee $employee): array
    {
        $this->ensureEligible($employee);
        $employee->loadMissing('payrollBaselines');
        $dailyRate = (float) ($employee->payrollBaselineForPt($employee->primaryPayrollPt())?->uang_harian ?? 0);
        $extraTime = ExtraTime::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_period_id', $period->id)
            ->with('details')
            ->first();
        $details = $extraTime?->details
            ->keyBy(fn ($detail) => $detail->attendance_date->toDateString())
            ?? collect();

        return [
            'eligible' => true,
            'daily_rate' => $dailyRate,
            'total_amount' => (float) ($extraTime?->amount ?? 0),
            'data' => $this->periodDates($period)->map(function (Carbon $date) use ($details) {
                $detail = $details->get($date->toDateString());

                return [
                    'date' => $date->toDateString(),
                    'day' => $date->locale('id')->translatedFormat('l'),
                    'selected' => $detail !== null,
                    'is_holiday' => $detail?->is_holiday ?? false,
                    'amount' => (float) ($detail?->amount ?? 0),
                    'notes' => $detail?->notes,
                ];
            })->values()->all(),
        ];
    }

    public function save(AttendancePeriod $period, Employee $employee, array $rows): array
    {
        $this->ensureEligible($employee);
        $validDates = $this->periodDates($period)->map->toDateString();
        $submittedDates = collect($rows)->pluck('date');
        if ($submittedDates->diff($validDates)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'rows' => 'Extra Time hanya dapat dicatat pada tanggal dalam periode penggajian.',
            ]);
        }
        if ($validDates->diff($submittedDates)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'rows' => 'Seluruh tanggal dalam periode penggajian harus dikirim saat menyimpan Extra Time.',
            ]);
        }

        $employee->loadMissing('payrollBaselines');
        $dailyRate = $employee->payrollBaselineForPt($employee->primaryPayrollPt())?->uang_harian;
        if (collect($rows)->contains(fn (array $row) => ($row['selected'] ?? false) && ($row['is_holiday'] ?? false)) && $dailyRate === null) {
            throw ValidationException::withMessages([
                'rows' => 'Uang Harian karyawan belum tersedia untuk menghitung Extra Time Hari Libur.',
            ]);
        }

        DB::transaction(function () use ($period, $employee, $rows, $dailyRate) {
            $extraTime = ExtraTime::query()->firstOrCreate(
                ['employee_id' => $employee->id, 'payroll_period_id' => $period->id],
                ['amount' => 0]
            );

            foreach ($rows as $row) {
                if (! ($row['selected'] ?? false)) {
                    $extraTime->details()->whereDate('attendance_date', $row['date'])->delete();

                    continue;
                }

                $isHoliday = (bool) ($row['is_holiday'] ?? false);
                $amount = $isHoliday
                    ? round(2 * (float) $dailyRate, 2)
                    : self::WORKDAY_AMOUNT;
                $detail = $extraTime->details()
                    ->whereDate('attendance_date', $row['date'])
                    ->first();
                $values = [
                    'is_holiday' => $isHoliday,
                    'amount' => $amount,
                    'notes' => $row['notes'] ?? null,
                ];

                if ($detail) {
                    $detail->update($values);
                } else {
                    $extraTime->details()->create([
                        'attendance_date' => $row['date'],
                        ...$values,
                    ]);
                }
            }

            $extraTime->update([
                'amount' => round((float) $extraTime->details()->sum('amount'), 2),
            ]);
        });

        return $this->details($period, $employee);
    }

    private function ensureEligible(Employee $employee): void
    {
        if (! $this->eligible($employee)) {
            throw ValidationException::withMessages([
                'employee_id' => 'Extra Time hanya tersedia untuk karyawan dengan jabatan Koordinator.',
            ]);
        }
    }

    private function periodDates(AttendancePeriod $period): Collection
    {
        $dates = $period->dates()
            ->orderBy('attendance_date')
            ->pluck('attendance_date')
            ->map(fn ($date) => Carbon::parse($date)->startOfDay());
        if ($dates->isNotEmpty()) {
            return $dates;
        }

        $startsOn = $period->starts_on
            ?? Carbon::create((int) $period->year, (int) $period->month, 21)->subMonthNoOverflow()->addDay();
        $endsOn = $period->ends_on
            ?? Carbon::create((int) $period->year, (int) $period->month, 21);

        return collect(CarbonPeriod::create($startsOn, $endsOn))
            ->map(fn ($date) => Carbon::instance($date)->startOfDay());
    }
}
