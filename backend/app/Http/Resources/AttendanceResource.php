<?php

namespace App\Http\Resources;

use App\Services\Attendance\AttendanceSummaryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $records = $this->attendanceRecords->keyBy(fn ($record) => $record->attendance_date->toDateString());

        return ['id' => $this->id, 'employee_number' => $this->employee_number, 'name' => $this->name, 'department' => $this->department, 'branch' => $this->branch, 'overtime_hours' => (float) optional($this->attendancePeriodEmployee->first())->monthly_overtime_hours, 'records' => $records->map(fn ($record) => ['id' => $record->id, 'date' => $record->attendance_date->toDateString(), 'status' => $record->status->value, 'remarks' => $record->remarks])->all(), 'summary' => app(AttendanceSummaryService::class)->forRecords($records)];
    }
}
