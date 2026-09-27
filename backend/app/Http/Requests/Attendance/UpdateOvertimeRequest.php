<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['attendance_period_id' => ['required', 'exists:attendance_periods,id'], 'employee_id' => ['required', 'exists:employees,id'], 'monthly_overtime_hours' => ['required', 'numeric', 'min:0', 'max:9999.99']];
    }
}
