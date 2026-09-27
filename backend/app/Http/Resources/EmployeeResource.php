<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'employee_number' => $this->employee_number, 'name' => $this->name, 'email' => $this->email, 'phone' => $this->phone,
            'gender' => $this->gender, 'birth_date' => $this->birth_date?->toDateString(), 'address' => $this->address,
            'emergency_contact_name' => $this->emergency_contact_name, 'emergency_contact_phone' => $this->emergency_contact_phone,
            'department' => $this->branch, 'position' => $this->position, 'branch' => $this->branch,
            'pts' => $this->pts ?: array_values(array_filter([$this->branch])),
            'bank_name' => $this->bank_name, 'bank_account_number' => $this->bank_account_number, 'tax_number' => $this->tax_number,
            'employment_status' => $this->employment_status, 'join_date' => $this->join_date?->toDateString(), 'active' => $this->active,
            'loan_amount' => $this->when($request->user()?->can('manage-loans'), (float) $this->loan_amount),
            'loan_balance' => $this->when($request->user()?->can('manage-loans'), (float) $this->loan_balance),
            'loan_installment' => $this->when($request->user()?->can('manage-loans'), (float) $this->loan_installment),
            'loan_start_date' => $this->when($request->user()?->can('manage-loans'), $this->loan_start_date?->toDateString()),
            'loan_notes' => $this->when($request->user()?->can('manage-loans'), $this->loan_notes),
        ];
    }
}
