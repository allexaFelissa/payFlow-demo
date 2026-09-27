<?php

namespace App\Services\Attendance;

use App\Enums\AttendancePeriodStatus;
use App\Enums\AttendanceStatus;
use App\Models\AttendancePeriod;
use App\Models\AttendancePeriodEmployee;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceMatrixService
{
    public function __construct(private readonly AttendanceSummaryService $summaries) {}

    public function generate(int $month, int $year, int $userId): AttendancePeriod
    {
        return DB::transaction(function () use ($month, $year, $userId) {
            $period = AttendancePeriod::firstOrCreate(['month' => $month, 'year' => $year], ['status' => AttendancePeriodStatus::Draft, 'created_by' => $userId]);
            if ($period->status !== AttendancePeriodStatus::Draft) {
                return $period;
            } $startsOn = Carbon::create($year, $month, 21)->subMonthNoOverflow()->addDay();
            $endsOn = Carbon::create($year, $month, 21);
            $dates = [];
            for ($date = $startsOn->copy(); $date->lessThanOrEqualTo($endsOn); $date->addDay()) {
                $dates[] = $date->copy();
            } if (! $period->dates()->exists()) {
                $period->dates()->createMany(array_map(fn ($date) => ['attendance_date' => $date->toDateString()], $dates));
                $period->update(['starts_on' => $startsOn->toDateString(), 'ends_on' => $endsOn->toDateString()]);
            } Employee::query()->where('active', true)->select('id')->chunkById(200, function ($employees) use ($period, $dates) {
                foreach ($employees as $employee) {
                    AttendancePeriodEmployee::firstOrCreate(['attendance_period_id' => $period->id, 'employee_id' => $employee->id]);
                    $records = [];
                    foreach ($dates as $date) {
                        if ($date->isWeekday()) {
                            $records[] = ['employee_id' => $employee->id, 'attendance_period_id' => $period->id, 'attendance_date' => $date->toDateString(), 'status' => AttendanceStatus::Present->value, 'created_at' => now(), 'updated_at' => now()];
                        }
                    } if ($records) {
                        AttendanceRecord::insertOrIgnore($records);
                    }
                }
            });

            return $period->refresh();
        });
    }

    public function matrix(AttendancePeriod $period, array $filters): LengthAwarePaginator
    {
        $query = Employee::query()->whereHas('attendancePeriodEmployee', fn ($q) => $q->where('attendance_period_id', $period->id));
        if ($filters['search'] ?? null) {
            $query->where(fn ($q) => $q->whereLike('name', '%'.$filters['search'].'%')->orWhereLike('employee_number', '%'.$filters['search'].'%'));
        } foreach (['department', 'branch', 'active'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                in_array($field, ['department', 'branch'], true)
                    ? $query->assignedToPt($filters[$field])
                    : $query->where($field, $filters[$field]);
            }
        }

        return $query->orderBy('name')->paginate(20);
    }

    public function updateCell(array $data): ?AttendanceRecord
    {
        $period = AttendancePeriod::findOrFail($data['attendance_period_id']);
        $this->assertEditable($period);
        if (empty($data['status'])) {
            AttendanceRecord::where(['attendance_period_id' => $period->id, 'employee_id' => $data['employee_id'], 'attendance_date' => $data['attendance_date']])->delete();
            if ($period->status === AttendancePeriodStatus::Locked) {
                $period->update(['status' => AttendancePeriodStatus::Draft]);
            }

return null;
        } $remarks = $data['remarks'] ?? null;
        if ($data['status'] === AttendanceStatus::Sick->value && empty($remarks)) {
            $remarks = 'SICK_YES';
        }
        $record = AttendanceRecord::updateOrCreate(['attendance_period_id' => $period->id, 'employee_id' => $data['employee_id'], 'attendance_date' => $data['attendance_date']], ['status' => $data['status'], 'remarks' => $remarks]);
        if ($period->status === AttendancePeriodStatus::Locked) {
            $period->update(['status' => AttendancePeriodStatus::Draft]);
        }

return $record;
    }

    public function updateOvertime(array $data): AttendancePeriodEmployee
    {
        $period = AttendancePeriod::findOrFail($data['attendance_period_id']);
        $this->assertEditable($period);
        $row = AttendancePeriodEmployee::updateOrCreate(['attendance_period_id' => $period->id, 'employee_id' => $data['employee_id']], ['monthly_overtime_hours' => $data['monthly_overtime_hours']]);
        if ($period->status === AttendancePeriodStatus::Locked) {
            $period->update(['status' => AttendancePeriodStatus::Draft]);
        }

return $row;
    }

    public function lock(int $periodId): AttendancePeriod
    {
        $period = AttendancePeriod::findOrFail($periodId);
        $this->assertEditable($period);
        $period->update(['status' => AttendancePeriodStatus::Locked]);

        return $period->refresh();
    }

    private function assertEditable(AttendancePeriod $period): void
    {
        if ($period->status === AttendancePeriodStatus::Closed) {
            throw ValidationException::withMessages(['attendance_period_id' => 'This attendance period is closed.']);
        }
    }
}
