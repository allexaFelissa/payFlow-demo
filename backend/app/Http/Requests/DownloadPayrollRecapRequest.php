<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DownloadPayrollRecapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pt' => ['required', 'string', Rule::in(config('employees.pts'))],
            'search' => ['nullable', 'string', 'max:150'],
            'transfer_amount' => ['required', 'numeric', 'min:0'],
            'cash_amount' => ['required', 'numeric', 'min:0'],
            'transfer_count' => ['required', 'integer', 'min:0'],
            'cash_count' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'pt.required' => 'Pilih PT terlebih dahulu untuk mengunduh Rekap Payroll.',
            'pt.in' => 'PT yang dipilih tidak valid.',
            'transfer_amount.required' => 'Jumlah transfer wajib dikonfirmasi.',
            'cash_amount.required' => 'Jumlah tunai wajib dikonfirmasi.',
            'transfer_count.required' => 'Jumlah karyawan transfer wajib dikonfirmasi.',
            'transfer_count.integer' => 'Jumlah karyawan transfer harus berupa bilangan bulat.',
            'cash_count.required' => 'Jumlah karyawan tunai wajib dikonfirmasi.',
            'cash_count.integer' => 'Jumlah karyawan tunai harus berupa bilangan bulat.',
        ];
    }
}
