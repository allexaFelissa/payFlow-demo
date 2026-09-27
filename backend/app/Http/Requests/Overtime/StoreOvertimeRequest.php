<?php

namespace App\Http\Requests\Overtime;

use Illuminate\Foundation\Http\FormRequest;

class StoreOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payroll_period_id' => ['required', 'integer', 'exists:attendance_periods,id'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.employee_id' => ['required', 'integer', 'distinct', 'exists:employees,id'],
            'rows.*.hours' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'rows.*.hourly_rate' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'rows.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
