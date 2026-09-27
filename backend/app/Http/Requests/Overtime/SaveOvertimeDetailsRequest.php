<?php

namespace App\Http\Requests\Overtime;

use Illuminate\Foundation\Http\FormRequest;

class SaveOvertimeDetailsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $rows = $this->input('rows');
        if (! is_array($rows)) {
            return;
        }

        $this->merge([
            'rows' => array_map(function ($row) {
                if (! is_array($row)) {
                    return $row;
                }

                $start = $row['starts_at'] ?? null;
                $end = $row['ends_at'] ?? null;
                if ($this->isEmptyOvertimeTime($start) && $this->isEmptyOvertimeTime($end)) {
                    $row['starts_at'] = null;
                    $row['ends_at'] = null;
                }

                return $row;
            }, $rows),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => ['required', 'array'],
            'rows.*.date' => ['required', 'date', 'distinct'],
            'rows.*.starts_at' => ['nullable', 'date_format:H:i'],
            'rows.*.ends_at' => ['nullable', 'date_format:H:i'],
            'rows.*.is_holiday' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'rows.*.starts_at.date_format' => 'Jam mulai lembur harus menggunakan format 24 jam HH:mm, contoh 20:00.',
            'rows.*.ends_at.date_format' => 'Jam selesai lembur harus menggunakan format 24 jam HH:mm, contoh 21:30.',
        ];
    }

    private function isEmptyOvertimeTime(mixed $value): bool
    {
        return in_array($value, [null, '', 0, '0', '00:00', '00:00:00'], true);
    }
}
