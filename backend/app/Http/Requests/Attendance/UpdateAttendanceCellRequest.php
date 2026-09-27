<?php

namespace App\Http\Requests\Attendance;

use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAttendanceCellRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['attendance_period_id' => ['required', 'exists:attendance_periods,id'], 'employee_id' => ['required', 'exists:employees,id'], 'attendance_date' => ['required', 'date'], 'status' => ['nullable', Rule::enum(AttendanceStatus::class)], 'remarks' => ['nullable', 'string', 'max:1000']];
    }
}
