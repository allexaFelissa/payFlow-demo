<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeLoanHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $period = $this->completion?->period;

        return [
            'period_month' => (int) ($period?->month ?? 0),
            'period_year' => (int) ($period?->year ?? 0),
            'installment_amount' => (float) $this->deduction_amount,
            'remaining_after' => round(max(0, (float) $this->loan_balance_before - (float) $this->deduction_amount), 2),
            'payroll_status' => 'Selesai',
            'processed_at' => $this->completion?->created_at?->toISOString(),
        ];
    }
}
