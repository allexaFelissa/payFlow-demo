<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('employee')) ?? false;
    }

    public function rules(): array
    {
        $employee = $this->route('employee');
        $isAdministrator = $this->user()->isAdministrator();
        $ptsRules = $isAdministrator
            ? ['nullable', 'required_without:department', 'array', 'min:1']
            : ['prohibited'];

        return [
            'employee_number' => [Rule::prohibitedIf(! $isAdministrator), Rule::requiredIf($isAdministrator), 'string', 'max:50', Rule::unique('employees')->ignore($employee)], 'name' => [Rule::prohibitedIf(! $isAdministrator), Rule::requiredIf($isAdministrator), 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('employees')->ignore($employee)], 'phone' => ['nullable', 'string', 'max:50'],
            'gender' => ['nullable', 'string', 'max:20'], 'birth_date' => ['nullable', 'date'], 'address' => ['nullable', 'string', 'max:1000'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'], 'emergency_contact_phone' => ['nullable', 'string', 'max:50'],
            'department' => [Rule::prohibitedIf(! $isAdministrator), 'nullable', 'string', Rule::in(config('employees.divisions'))], 'pts' => $ptsRules, 'pts.*' => ['required', 'string', 'distinct', Rule::in(config('employees.pts'))], 'position' => ['nullable', 'string', 'max:100'], 'branch' => [Rule::prohibitedIf(! $isAdministrator), 'nullable', 'string', Rule::in(config('employees.pts'))],
            'bank_name' => ['nullable', 'string', 'max:100'], 'bank_account_number' => ['nullable', 'string', 'max:100'], 'tax_number' => ['nullable', 'string', 'max:100'],
            'employment_status' => ['nullable', 'string', 'max:50'], 'join_date' => ['nullable', 'date'], 'active' => ['required', 'boolean'],
            'loan_amount' => [Rule::prohibitedIf(! $this->user()->isAdministrator()), 'nullable', 'numeric', 'min:0'], 'loan_balance' => ['prohibited'],
            'loan_installment' => [Rule::prohibitedIf(! $this->user()->isAdministrator()), Rule::when((float) $this->input('loan_amount', 0) > 0, ['required', 'numeric', 'gt:0', 'lte:loan_amount'], ['nullable', 'numeric', 'min:0'])], 'loan_start_date' => [Rule::prohibitedIf(! $this->user()->isAdministrator()), 'nullable', 'date'], 'loan_notes' => [Rule::prohibitedIf(! $this->user()->isAdministrator()), 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_number.unique' => 'ID karyawan sudah digunakan oleh karyawan lain. Gunakan ID yang berbeda.',
            'employee_number.required' => 'ID karyawan wajib diisi.',
            'name.required' => 'Nama karyawan wajib diisi.',
            'pts.required' => 'Pilih minimal satu PT.',
            'pts.min' => 'Pilih minimal satu PT.',
            'loan_amount.numeric' => 'Jumlah Pinjaman Awal harus berupa angka.',
            'loan_amount.min' => 'Jumlah Pinjaman Awal tidak boleh bernilai negatif.',
            'loan_balance.prohibited' => 'Sisa Pinjaman dihitung otomatis dan tidak dapat diisi manual.',
            'loan_installment.required' => 'Angsuran Default wajib diisi ketika terdapat pinjaman.',
            'loan_installment.numeric' => 'Angsuran Default harus berupa angka.',
            'loan_installment.gt' => 'Angsuran Default harus lebih dari 0.',
            'loan_installment.min' => 'Angsuran Default tidak boleh bernilai negatif.',
            'loan_installment.lte' => 'Angsuran Default tidak boleh melebihi Jumlah Pinjaman Awal.',
        ];
    }
}
