<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalculatePayrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['payroll_period_id' => ['required', 'integer', 'exists:attendance_periods,id'], 'search' => ['nullable', 'string', 'max:100'], 'pt' => ['nullable', 'string', 'max:100']];
    }
}
