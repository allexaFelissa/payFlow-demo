<?php

namespace App\Services\Payroll;

use App\Models\AttendancePeriod;
use App\Models\Employee;
use App\Models\PayrollCalculation;
use App\Models\PayrollCompletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollCompletionService
{
    public function __construct(private readonly PayrollSalarySlipService $salarySlips) {}

    public function status(AttendancePeriod $period, string $pt): bool
    {
        return PayrollCompletion::query()->where('attendance_period_id', $period->id)->where('pt', $pt)->exists();
    }

    public function complete(AttendancePeriod $period, string $pt, ?int $userId): PayrollCompletion
    {
        return DB::transaction(function () use ($period, $pt, $userId) {
            $completion = PayrollCompletion::query()->where('attendance_period_id', $period->id)->where('pt', $pt)->lockForUpdate()->first();
            if ($completion) {
                return $completion;
            }

            $calculations = PayrollCalculation::query()->where('payroll_period_id', $period->id)
                ->where(function ($query) use ($pt) {
                    $query->where('pt', $pt)
                        ->orWhere(fn ($legacy) => $legacy->whereNull('pt')->whereHas('employee', fn ($employee) => $employee->where('branch', $pt)));
                })
                ->with(['employee', 'manualAdjustments', 'loanDetail'])->get();
            if ($calculations->isEmpty()) {
                throw ValidationException::withMessages(['pt' => 'Belum ada perhitungan payroll untuk PT yang dipilih.']);
            }
            if ($calculations->contains(fn ($calculation) => (float) $calculation->potongan_pinjaman > 0 && ! $calculation->loanDetail)) {
                throw ValidationException::withMessages(['pt' => 'Data acuan pinjaman belum tersedia. Hitung ulang payroll sebelum menandai selesai.']);
            }

            $completion = PayrollCompletion::create(['attendance_period_id' => $period->id, 'pt' => $pt, 'completed_by' => $userId]);
            foreach ($calculations as $calculation) {
                $loanDetail = $calculation->loanDetail;
                if (! $loanDetail || (float) $loanDetail->deducted_amount <= 0) {
                    continue;
                }

                $employee = Employee::query()->lockForUpdate()->findOrFail($calculation->employee_id);
                $manualDeduction = (float) $calculation->manualAdjustments->where('type', 'deduction')->sum('amount');
                $nonLoanDeduction = max(0, (float) $calculation->total_deduction - (float) $calculation->potongan_pinjaman) + $manualDeduction;
                $availableForLoan = max(0, PayrollCalculation::MAX_TOTAL_DEDUCTION - $nonLoanDeduction);
                $balanceBefore = max(0, (float) $employee->loan_balance);
                $deduction = round(min((float) $loanDetail->deducted_amount, $balanceBefore, $availableForLoan), 2);
                $remaining = round($balanceBefore - $deduction, 2);
                $loanDetail->update(['deducted_amount' => $deduction, 'remaining_before' => $balanceBefore, 'remaining_after' => $remaining]);

                if ($deduction !== round((float) $calculation->potongan_pinjaman, 2)) {
                    $oldDeduction = (float) $calculation->potongan_pinjaman;
                    $calculation->update([
                        'potongan_pinjaman' => $deduction,
                        'total_deduction' => round(max(0, (float) $calculation->total_deduction - $oldDeduction + $deduction), 2),
                        'take_home_pay' => round((float) $calculation->take_home_pay + $oldDeduction - $deduction, 2),
                    ]);
                }
                if ($deduction <= 0) {
                    continue;
                }
                $completion->loanFinalizations()->create([
                    'employee_id' => $calculation->employee_id,
                    'deduction_amount' => $deduction,
                    'loan_amount_before' => $employee->loan_amount,
                    'loan_balance_before' => $balanceBefore,
                    'loan_installment_before' => $employee->loan_installment,
                    'loan_start_date_before' => $employee->loan_start_date,
                    'loan_notes_before' => $employee->loan_notes,
                ]);
                $employee->update($remaining === 0.0
                    ? ['loan_amount' => 0, 'loan_balance' => 0, 'loan_installment' => 0, 'loan_start_date' => null, 'loan_notes' => null]
                    : ['loan_balance' => $remaining]);
            }

            PayrollCalculation::query()->whereKey($calculations->pluck('id'))->update(['status' => 'completed']);
            foreach ($calculations as $calculation) {
                $this->salarySlips->capture($completion, $calculation);
            }

            return $completion;
        });
    }

    public function undo(AttendancePeriod $period, string $pt): void
    {
        DB::transaction(function () use ($period, $pt) {
            $completion = PayrollCompletion::query()->where('attendance_period_id', $period->id)->where('pt', $pt)->with('loanFinalizations')->lockForUpdate()->first();
            if (! $completion) {
                throw ValidationException::withMessages(['pt' => 'Payroll PT ini belum berstatus selesai.']);
            }

            foreach ($completion->loanFinalizations as $finalization) {
                $employee = Employee::query()->lockForUpdate()->findOrFail($finalization->employee_id);
                $employee->update([
                    'loan_amount' => $finalization->loan_amount_before,
                    'loan_balance' => $finalization->loan_balance_before,
                    'loan_installment' => $finalization->loan_installment_before,
                    'loan_start_date' => $finalization->loan_start_date_before,
                    'loan_notes' => $finalization->loan_notes_before,
                ]);
            }
            PayrollCalculation::query()->where('payroll_period_id', $period->id)
                ->where(function ($query) use ($pt) {
                    $query->where('pt', $pt)
                        ->orWhere(fn ($legacy) => $legacy->whereNull('pt')->whereHas('employee', fn ($employee) => $employee->where('branch', $pt)));
                })
                ->update(['status' => 'draft']);
            $completion->delete();
        });
    }
}
