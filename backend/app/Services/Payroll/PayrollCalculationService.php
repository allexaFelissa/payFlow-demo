<?php

namespace App\Services\Payroll;

use App\Models\AttendancePeriod;
use App\Models\Employee;
use App\Models\PayrollBaseline;
use App\Models\PayrollCalculation;
use App\Models\PayrollCompletion;
use App\Services\Overtime\ExtraTimeService;
use App\Services\Overtime\OvertimeAmountCalculator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollCalculationService
{
    public function __construct(
        private readonly OvertimeAmountCalculator $overtimeAmountCalculator,
        private readonly ExtraTimeService $extraTime,
        private readonly Pph21GrossUpCalculator $pph21
    ) {}

    public function calculate(AttendancePeriod $period, array $filters = []): Collection
    {
        $configuration = PayrollBaseline::active()->latest('id')->first();
        if (! $configuration) {
            throw ValidationException::withMessages(['payroll_period_id' => 'Payroll tidak dapat dihitung karena baseline payroll global belum tersedia.']);
        }
        if (($filters['pt'] ?? null) && PayrollCompletion::query()->where('attendance_period_id', $period->id)->where('pt', $filters['pt'])->exists()) {
            throw ValidationException::withMessages(['pt' => 'Payroll PT ini sudah selesai. Gunakan Batalkan Selesai untuk menghitung ulang.']);
        }

        $completedPts = PayrollCompletion::query()->where('attendance_period_id', $period->id)->pluck('pt')->all();

        $employees = Employee::query()
            ->where(function ($query) use ($period) {
                $query->where('active', true)
                    ->orWhereHas('attendancePeriodEmployee', fn ($attendance) => $attendance->where('attendance_period_id', $period->id));
            })
            ->when($filters['search'] ?? null, function ($query, $search) {
                $normalized = $this->normalizeEmployeeSearch((string) $search);
                $query->where(function ($nested) use ($search, $normalized) {
                    $nested->whereLike('name', '%'.$search.'%')
                        ->orWhereLike('employee_number', '%'.$search.'%')
                        ->orWhereRaw("LOWER(REPLACE(REPLACE(name, '''', ''), ' ', '')) LIKE ?", ['%'.$normalized.'%']);
                });
            })
            ->when($filters['pt'] ?? null, fn ($query, $pt) => $query->assignedToPt($pt))
            ->with([
                'payrollBaselines',
                'attendanceRecords' => fn ($query) => $query->where('attendance_period_id', $period->id),
                'overtimes' => fn ($query) => $query->where('payroll_period_id', $period->id)->with('details'),
                'extraTimes' => fn ($query) => $query->where('payroll_period_id', $period->id),
            ])->get()->flatMap(function ($employee) use ($filters, $completedPts) {
                $pts = ($filters['pt'] ?? null)
                    ? [$filters['pt']]
                    : ($employee->assignedPts() ?: [config('employees.pts.0')]);

                return collect($pts)->unique()
                    ->reject(fn ($pt) => in_array($pt, $completedPts, true))
                    ->map(function ($pt) use ($employee) {
                        $row = clone $employee;
                        $row->setAttribute('payroll_pt', $pt);
                        $row->setRelation(
                            'payrollBaseline',
                            $employee->payrollBaselines->firstWhere('pt', $pt)
                        );

                        return $row;
                    });
            })->values();
        if ($employees->isEmpty()) {
            throw ValidationException::withMessages(['payroll_period_id' => 'Payroll tidak dapat dihitung karena tidak ada karyawan aktif yang sesuai filter atau seluruh PT sudah selesai.']);
        }

        $missing = $employees
            ->filter(fn ($employee) => ! $employee->payrollBaseline)
            ->map(fn ($employee) => $employee->name.' ('.$employee->getAttribute('payroll_pt').')')
            ->unique()
            ->values();
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['payroll_period_id' => 'Payroll tidak dapat dihitung karena baseline payroll belum tersedia untuk '.$missing->implode(', ').'.']);
        }
        $missingPtkp = $employees->filter(fn ($employee) => ! $employee->family_status)
            ->map(fn ($employee) => $employee->name.' ('.$employee->getAttribute('payroll_pt').')')->unique()->values();
        if ($missingPtkp->isNotEmpty()) {
            throw ValidationException::withMessages(['payroll_period_id' => 'Payroll tidak dapat dihitung karena status PTKP belum tersedia untuk '.$missingPtkp->implode(', ').'.']);
        }

        return DB::transaction(function () use ($period, $configuration, $employees) {
            foreach ($employees as $employee) {
                $calculationPt = $employee->getAttribute('payroll_pt');
                $baseline = $employee->payrollBaseline;
                $records = $employee->attendanceRecords;
                $counts = ['M' => 0, 'L' => 0, 'S' => 0, 'I' => 0, 'C' => 0, 'A' => 0];
                foreach ($records as $record) {
                    $counts[$record->status->value]++;
                }

                $tunj2 = round((float) $baseline->tunj_antar_cabang + (float) $baseline->tunj_komunikasi + (float) $baseline->tunj_kost + (float) $baseline->tunj_jabatan, 2);
                $dailySalary = (float) $baseline->uang_harian;
                $dailySalaryDays = 0;
                $lateDeduction = 0;
                $incentiveEligible = $records->isNotEmpty();
                foreach ($records as $record) {
                    if (in_array($record->status->value, ['M', 'L'], true)) {
                        $dailySalaryDays++;
                    }
                    if ($record->status->value === 'L') {
                        $lateDeduction += $record->remarks === 'GT30' ? $dailySalary : $dailySalary / 2;
                    }
                    if (in_array($record->status->value, ['I', 'A'], true) || ($record->status->value === 'S' && $record->remarks !== 'SICK_YES')) {
                        $incentiveEligible = false;
                    }
                }
                $dailySalaryAmount = round($dailySalary * $dailySalaryDays, 2);
                $lateDeduction = round($lateDeduction, 2);
                $alphaDeduction = round($counts['A'] * (float) $baseline->potongan_alpha, 2);
                $incentive = $incentiveEligible && $baseline->incentive_eligible
                    ? round((float) $baseline->gaji_pokok * ((float) $configuration->insentif_default / 100), 2)
                    : 0;
                $usesExtraTime = $this->extraTime->eligible($employee);
                [$overtimeHours, $overtimeAmount] = $usesExtraTime
                    ? [0.0, 0.0]
                    : $this->overtimeFor($employee->overtimes, $dailySalary, $configuration);
                $extraTimeAmount = $usesExtraTime
                    ? round((float) ($employee->extraTimes->first()?->amount ?? 0), 2)
                    : 0;
                // The reference payroll workbook keeps four decimal places for percentage-based
                // BPJS amounts. Gross I and Gross II must use those unrounded tax-base values;
                // rounding every component to cents here creates a small cumulative difference.
                $bpjsTkBase = (float) $baseline->dasar_perhitungan_bpjs_tk;
                $pensionBase = min($bpjsTkBase, (float) config('payroll.bpjs_tk.pension_wage_cap'));
                $companyPensionRate = (float) config('payroll.bpjs_tk.company_pension_rate');
                $employeePensionRate = (float) config('payroll.bpjs_tk.employee_pension_rate');
                $companyJhtRate = max(0, (float) $configuration->jht_pens - $companyPensionRate);
                $employeeJhtRate = max(0, (float) $configuration->potgn_tk - $employeePensionRate);

                $bpjsKesPt = round((float) $baseline->dasar_perhitungan_bpjs_kes * ((float) $configuration->bpjs_p / 100), 4);
                $jkkJkmRate = (float) config(
                    'payroll.jkk_jkm_rates_by_pt.'.$calculationPt,
                    (float) $configuration->jkk_jkm
                );
                $jkkJkmPt = round($bpjsTkBase * ($jkkJkmRate / 100), 4);
                $jhtPensPt = round(
                    ($bpjsTkBase * ($companyJhtRate / 100))
                    + ($pensionBase * ($companyPensionRate / 100)),
                    4
                );
                $tunjBpjsBebanPt = round($bpjsKesPt + $jkkJkmPt + $jhtPensPt, 4);
                $potonganTk = round(
                    ($bpjsTkBase * ($employeeJhtRate / 100))
                    + ($pensionBase * ($employeePensionRate / 100)),
                    4
                );
                $potonganKes = round((float) $baseline->dasar_perhitungan_bpjs_kes * ((float) $configuration->potgn_kes / 100), 4);
                $existingCalculation = PayrollCalculation::query()
                    ->where('payroll_period_id', $period->id)
                    ->where('employee_id', $employee->id)
                    ->where('pt', $calculationPt)
                    ->with(['loanDetail', 'manualAdjustments'])
                    ->first();
                $deductLoanHere = $calculationPt === ($employee->branch ?: config('employees.pts.0'));
                $loanSnapshot = [
                    'employee_id' => $employee->id,
                    'loan_amount' => (float) $employee->loan_amount,
                    'installment_amount' => $deductLoanHere ? (float) $employee->loan_installment : 0,
                    'remaining_before' => (float) $employee->loan_balance,
                    'loan_start_date' => $employee->loan_start_date,
                    'loan_notes' => $employee->loan_notes,
                ];
                $loanDeduction = round(max(0, min($loanSnapshot['remaining_before'], $loanSnapshot['installment_amount'])), 2);
                $loanSnapshot['deducted_amount'] = $loanDeduction;
                $loanSnapshot['remaining_after'] = round(max(0, $loanSnapshot['remaining_before'] - $loanDeduction), 2);
                $absenceDeduction = $lateDeduction;
                $thr = (float) $baseline->thr;
                $grossIBeforePph21 = round((float) $baseline->gaji_pokok + $dailySalaryAmount + $incentive + $tunj2 + $overtimeAmount + $extraTimeAmount + $tunjBpjsBebanPt + $thr, 4);
                $grossIIBeforePph21 = round($grossIBeforePph21 - $absenceDeduction - $alphaDeduction - $potonganTk, 4);
                $manualIncome = (float) ($existingCalculation?->manualAdjustments?->where('type', 'income')->sum('amount') ?? 0);
                $taxableGrossBeforePph21 = $grossIIBeforePph21 + $manualIncome;
                $pph21Allowance = (int) $period->month === 12
                    ? $this->finalPeriodPph21($period, $employee, $calculationPt, $taxableGrossBeforePph21, $potonganTk)
                    : $this->pph21->monthly($taxableGrossBeforePph21, $employee->family_status);
                $grossI = round($grossIBeforePph21 + $pph21Allowance, 4);
                $grossII = round($grossIIBeforePph21 + $pph21Allowance, 4);
                $deductions = round($absenceDeduction + $alphaDeduction + $potonganTk + $potonganKes + $tunjBpjsBebanPt + $pph21Allowance + $loanDeduction, 4);
                $netIncome = round($grossII - $loanDeduction - $tunjBpjsBebanPt - $potonganKes - $pph21Allowance, 2);

                $calculation = PayrollCalculation::updateOrCreate(
                    ['payroll_period_id' => $period->id, 'employee_id' => $employee->id, 'pt' => $calculationPt],
                    ['gaji_pokok' => $baseline->gaji_pokok, 'uang_harian' => $dailySalaryAmount, 'tunj_antar_cabang' => $baseline->tunj_antar_cabang, 'tunj_komunikasi' => $baseline->tunj_komunikasi, 'tunj_kost' => $baseline->tunj_kost, 'tunj_jabatan' => $baseline->tunj_jabatan, 'tunj2' => $tunj2, 'thr' => $thr, 'tunj_pph21' => $pph21Allowance, 'bpjs_kes_pt' => $bpjsKesPt, 'jkk_jkm_pt' => $jkkJkmPt, 'jht_pens_pt' => $jhtPensPt, 'tunj_bpjs_beban_pt' => $tunjBpjsBebanPt, 'hadir' => $counts['M'] + $counts['L'], 'izin' => $counts['I'], 'sakit' => $counts['S'], 'cuti' => $counts['C'], 'alpha' => $counts['A'], 'late_minutes' => 0, 'overtime_hours' => $overtimeHours, 'overtime_amount' => $overtimeAmount, 'extra_time_amount' => $extraTimeAmount, 'insentif' => $incentive, 'potongan_tk' => $potonganTk, 'potongan_kes' => $potonganKes, 'potongan_alpha' => $alphaDeduction, 'potongan_terlambat' => $lateDeduction, 'potongan_pinjaman' => $loanDeduction, 'potongan_lain' => 0, 'potongan_absensi' => $absenceDeduction, 'potongan_tunj_pt' => $tunjBpjsBebanPt, 'potongan_jht_pens' => $potonganTk, 'potongan_bpjs_karyawan' => $potonganKes, 'potongan_tunj_pph21' => $pph21Allowance, 'gross_i' => $grossI, 'gross_ii' => $grossII, 'gross_income' => $grossI, 'total_deduction' => $deductions, 'take_home_pay' => $netIncome, 'status' => 'draft']
                );
                $calculation->loanDetail()->updateOrCreate([], $loanSnapshot);
            }

            return $this->drafts(
                $period,
                $employees->pluck('id')->all(),
                $employees->pluck('payroll_pt')->unique()->values()->all()
            );
        });
    }

    public function drafts(AttendancePeriod $period, ?array $employeeIds = null, ?array $pts = null): Collection
    {
        return PayrollCalculation::query()->where('payroll_period_id', $period->id)
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds))
            ->when($pts !== null, fn ($query) => $query->whereIn('pt', $pts))
            ->with(['employee', 'manualAdjustments', 'loanDetail'])
            ->join('employees', 'employees.id', '=', 'payroll_calculations.employee_id')
            ->select('payroll_calculations.*')
            ->orderBy('employees.name')
            ->orderBy('payroll_calculations.pt')
            ->get();
    }

    public function refreshPph21ForManualIncome(PayrollCalculation $calculation): PayrollCalculation
    {
        $calculation->loadMissing(['employee', 'payrollPeriod', 'manualAdjustments']);
        $oldPph21 = (float) $calculation->tunj_pph21;
        $automaticGrossBeforePph21 = (float) $calculation->gross_ii - $oldPph21;
        $manualIncome = (float) $calculation->manualAdjustments->where('type', 'income')->sum('amount');
        $taxableGrossBeforePph21 = $automaticGrossBeforePph21 + $manualIncome;
        $period = $calculation->payrollPeriod;
        $employee = $calculation->employee;
        $newPph21 = (int) $period->month === 12
            ? $this->finalPeriodPph21($period, $employee, $calculation->pt, $taxableGrossBeforePph21, (float) $calculation->potongan_jht_pens)
            : $this->pph21->monthly($taxableGrossBeforePph21, $employee->family_status);
        $difference = $newPph21 - $oldPph21;

        $calculation->update([
            'tunj_pph21' => $newPph21,
            'potongan_tunj_pph21' => $newPph21,
            'gross_i' => round((float) $calculation->gross_i + $difference, 4),
            'gross_ii' => round((float) $calculation->gross_ii + $difference, 4),
            'gross_income' => round((float) $calculation->gross_income + $difference, 4),
            'total_deduction' => round((float) $calculation->total_deduction + $difference, 4),
        ]);

        return $calculation->fresh(['employee', 'payrollPeriod', 'manualAdjustments', 'loanDetail']) ?? $calculation;
    }

    private function overtimeFor(Collection $overtimes, float $dailySalary, PayrollBaseline $configuration): array
    {
        $result = $this->overtimeAmountCalculator->calculate(
            $overtimes->flatMap(fn ($overtime) => $overtime->details),
            $dailySalary,
            $configuration
        );

        return [$result['hours'], $result['amount']];
    }

    private function normalizeEmployeeSearch(string $value): string
    {
        return strtolower(str_replace(["'", ' '], '', trim($value)));
    }

    private function finalPeriodPph21(
        AttendancePeriod $period,
        Employee $employee,
        string $pt,
        float $currentGrossBeforeAllowance,
        float $currentEmployeeContribution
    ): float {
        $history = PayrollCalculation::query()
            ->join('attendance_periods', 'attendance_periods.id', '=', 'payroll_calculations.payroll_period_id')
            ->where('payroll_calculations.employee_id', $employee->id)
            ->where('payroll_calculations.pt', $pt)
            ->where('attendance_periods.year', $period->year)
            ->where('attendance_periods.month', '<', $period->month)
            ->selectRaw('COALESCE(SUM(payroll_calculations.gross_income), 0) as gross_income')
            ->selectRaw('COALESCE(SUM(payroll_calculations.potongan_tunj_pph21), 0) as pph21')
            ->selectRaw('COALESCE(SUM(payroll_calculations.potongan_jht_pens), 0) as employee_contributions')
            ->selectRaw('COUNT(DISTINCT attendance_periods.month) as income_months')
            ->first();

        return $this->pph21->finalPeriod(
            (float) $history->gross_income + $currentGrossBeforeAllowance,
            (float) $history->pph21,
            (float) $history->employee_contributions + $currentEmployeeContribution,
            (int) $history->income_months + 1,
            $employee->family_status
        );
    }
}
