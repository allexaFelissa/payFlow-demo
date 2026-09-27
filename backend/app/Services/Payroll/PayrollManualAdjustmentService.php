<?php

namespace App\Services\Payroll;

use App\Models\PayrollCalculation;
use App\Models\PayrollCompletion;
use App\Models\PayrollManualAdjustment;
use Illuminate\Validation\ValidationException;

class PayrollManualAdjustmentService
{
    public function __construct(private readonly PayrollCalculationService $calculations) {}

    public function create(PayrollCalculation $calculation, array $data): PayrollCalculation
    {
        $this->ensureEditable($calculation);
        $calculation->manualAdjustments()->create([
            'employee_id' => $calculation->employee_id,
            'payroll_period_id' => $calculation->payroll_period_id,
            ...$data,
        ]);

        return $this->refreshCalculation($calculation);
    }

    public function update(PayrollCalculation $calculation, PayrollManualAdjustment $adjustment, array $data): PayrollCalculation
    {
        $this->ensureOwnedAdjustment($calculation, $adjustment);
        $this->ensureEditable($calculation);
        $adjustment->update($data);

        return $this->refreshCalculation($calculation);
    }

    public function delete(PayrollCalculation $calculation, PayrollManualAdjustment $adjustment): PayrollCalculation
    {
        $this->ensureOwnedAdjustment($calculation, $adjustment);
        $this->ensureEditable($calculation);
        $adjustment->delete();

        return $this->refreshCalculation($calculation);
    }

    private function ensureEditable(PayrollCalculation $calculation): void
    {
        $calculation->loadMissing('employee');
        $isCompleted = PayrollCompletion::query()
            ->where('attendance_period_id', $calculation->payroll_period_id)
            ->where('pt', $calculation->pt ?: $calculation->employee?->branch)
            ->exists();
        if ($calculation->status !== 'draft' || $isCompleted) {
            throw ValidationException::withMessages(['payroll' => 'Perhitungan payroll yang sudah selesai tidak dapat diubah.']);
        }
    }

    private function ensureOwnedAdjustment(PayrollCalculation $calculation, PayrollManualAdjustment $adjustment): void
    {
        if ($adjustment->payroll_calculation_id !== $calculation->id) {
            throw ValidationException::withMessages(['adjustment' => 'Komponen manual tidak sesuai dengan perhitungan payroll ini.']);
        }
    }

    private function refreshCalculation(PayrollCalculation $calculation): PayrollCalculation
    {
        return $this->calculations->refreshPph21ForManualIncome(
            $calculation->fresh(['employee', 'payrollPeriod', 'manualAdjustments', 'loanDetail']) ?? $calculation
        );
    }
}
