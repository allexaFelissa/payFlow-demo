<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavePayrollManualAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:income,deduction'],
            'component_name' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
        ];
    }
}
