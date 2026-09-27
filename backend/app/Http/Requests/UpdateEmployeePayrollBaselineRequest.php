<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeePayrollBaselineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [...array_fill_keys(['gaji_pokok', 'uang_harian', 'tunj_antar_cabang', 'tunj_komunikasi', 'tunj_kost', 'tunj_jabatan', 'thr', 'dasar_perhitungan_bpjs_tk', 'dasar_perhitungan_bpjs_kes', 'potongan_alpha'], ['required', 'numeric', 'min:0', 'max:9999999999999.99']), 'incentive_eligible' => ['sometimes', 'boolean'], 'pt' => ['required', 'string', Rule::in(config('employees.pts'))], 'title' => ['nullable', 'string', 'max:100'], 'family_status' => ['nullable', Rule::in(['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3'])]];
    }
}
