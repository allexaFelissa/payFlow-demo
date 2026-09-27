<?php

namespace App\Http\Requests\Overtime;

use Illuminate\Foundation\Http\FormRequest;

class SaveExtraTimeDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.date' => ['required', 'date', 'distinct'],
            'rows.*.selected' => ['required', 'boolean'],
            'rows.*.is_holiday' => ['required', 'boolean'],
            'rows.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'rows.required' => 'Data Extra Time harus diisi.',
            'rows.array' => 'Data Extra Time tidak valid.',
            'rows.min' => 'Data Extra Time harus memuat setidaknya satu tanggal.',
            'rows.*.date.required' => 'Tanggal harus diisi.',
            'rows.*.date.date' => 'Tanggal tidak valid.',
            'rows.*.date.distinct' => 'Tanggal Extra Time tidak boleh sama.',
            'rows.*.selected.required' => 'Pilihan Extra Time harus diisi.',
            'rows.*.selected.boolean' => 'Pilihan Extra Time tidak valid.',
            'rows.*.is_holiday.required' => 'Jenis hari harus diisi.',
            'rows.*.is_holiday.boolean' => 'Jenis hari tidak valid.',
            'rows.*.notes.string' => 'Catatan harus berupa teks.',
            'rows.*.notes.max' => 'Catatan maksimal 500 karakter.',
        ];
    }
}
