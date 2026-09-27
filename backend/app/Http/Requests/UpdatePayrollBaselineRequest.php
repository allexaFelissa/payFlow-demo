<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePayrollBaselineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'jht_pens' => ['required', 'numeric', 'min:0', 'max:100'],
            'jkk_jkm' => ['required', 'numeric', 'min:0', 'max:100'],
            'bpjs_p' => ['required', 'numeric', 'min:0', 'max:100'],
            'potgn_tk' => ['required', 'numeric', 'min:0', 'max:100'],
            'potgn_kes' => ['required', 'numeric', 'min:0', 'max:100'],
            'lembur_hari_kerja_rate' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'lembur_hari_libur_multiplier' => ['required', 'numeric', 'min:0', 'max:100'],
            'insentif_default' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
        ];
    }
}
