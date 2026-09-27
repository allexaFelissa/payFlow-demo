<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayrollCompletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['payroll_period_id' => ['required', 'integer', 'exists:attendance_periods,id'], 'pt' => ['required', 'string', Rule::in(config('employees.pts'))]];
    }
}
