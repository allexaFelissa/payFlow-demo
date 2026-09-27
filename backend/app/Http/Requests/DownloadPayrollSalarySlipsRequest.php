<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DownloadPayrollSalarySlipsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pt' => ['nullable', 'string', Rule::in(config('employees.pts'))],
            'search' => ['nullable', 'string', 'max:150'],
        ];
    }
}
