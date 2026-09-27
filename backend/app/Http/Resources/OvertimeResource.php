<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OvertimeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'payroll_period_id' => $this->payroll_period_id,
            'employee_number' => $this->employee?->employee_number,
            'employee_name' => $this->employee?->name,
            'position' => $this->employee?->position,
            'branch' => $this->employee?->branch,
            'hours' => (float) $this->hours,
            'hourly_rate' => (float) $this->hourly_rate,
            'amount' => (float) $this->amount,
            'overtime_eligible' => (bool) ($this->overtime_eligible ?? true),
            'extra_time_eligible' => (bool) ($this->extra_time_eligible ?? false),
            'extra_time_amount' => (float) ($this->extra_time_amount ?? 0),
            'notes' => $this->notes,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
