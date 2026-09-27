<?php

namespace App\Http\Resources;

use App\Models\PayrollCompletion;
use App\Services\Overtime\ExtraTimeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollCalculationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $adjustments = $this->whenLoaded('manualAdjustments', fn () => $this->manualAdjustments, collect());
        $manualIncome = (float) $adjustments->where('type', 'income')->sum('amount');
        $manualDeduction = (float) $adjustments->where('type', 'deduction')->sum('amount');
        $transactionPt = $this->pt ?: $this->employee?->branch;
        $isCompleted = PayrollCompletion::query()
            ->where('attendance_period_id', $this->payroll_period_id)
            ->where('pt', $transactionPt)
            ->exists();
        if ($this->employee) {
            // The response must identify the PT of this payroll transaction, not the employee's primary PT.
            $this->employee->branch = $transactionPt;
        }
        $automaticIncome = (float) $this->gross_income;
        $automaticDeduction = (float) $this->total_deduction;
        $grossI = round((float) $this->gross_i + $manualIncome, 2);
        $grossII = round((float) $this->gross_ii + $manualIncome, 2);
        $totalIncome = round($automaticIncome + $manualIncome, 2);
        $totalDeduction = $this->cappedTotalDeduction($manualDeduction);
        $loanDetail = $this->relationLoaded('loanDetail') ? $this->loanDetail : null;
        $usesExtraTime = $this->employee
            ? app(ExtraTimeService::class)->eligible($this->employee)
            : false;

        return [
            'id' => $this->id,
            'payroll_period_id' => $this->payroll_period_id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee?->name,
            'employee_number' => $this->employee?->employee_number,
            'employee_position' => $this->employee?->position,
            'uses_extra_time' => $usesExtraTime,
            'pt' => $this->employee?->branch,
            'gaji_pokok' => (float) $this->gaji_pokok,
            'uang_harian' => (float) $this->uang_harian,
            'tunj_antar_cabang' => (float) $this->tunj_antar_cabang,
            'tunj_komunikasi' => (float) $this->tunj_komunikasi,
            'tunj_kost' => (float) $this->tunj_kost,
            'tunj_jabatan' => (float) $this->tunj_jabatan,
            'tunj2' => (float) $this->tunj2,
            'thr' => (float) $this->thr,
            'tunj_pph21' => (float) $this->tunj_pph21,
            'bpjs_kes_pt' => (float) $this->bpjs_kes_pt,
            'jkk_jkm_pt' => (float) $this->jkk_jkm_pt,
            'jht_pens_pt' => (float) $this->jht_pens_pt,
            'tunj_bpjs_beban_pt' => (float) $this->tunj_bpjs_beban_pt,
            'hadir' => $this->hadir,
            'izin' => $this->izin,
            'sakit' => $this->sakit,
            'cuti' => $this->cuti,
            'alpha' => $this->alpha,
            'late_minutes' => $this->late_minutes,
            'overtime_hours' => (float) $this->overtime_hours,
            'overtime_amount' => (float) $this->overtime_amount,
            'extra_time_amount' => (float) $this->extra_time_amount,
            'insentif' => (float) $this->insentif,
            'potongan_tk' => (float) $this->potongan_tk,
            'potongan_kes' => (float) $this->potongan_kes,
            'potongan_alpha' => (float) $this->potongan_alpha,
            'potongan_terlambat' => (float) $this->potongan_terlambat,
            'potongan_pinjaman' => (float) $this->potongan_pinjaman,
            'angsuran_default' => (float) ($loanDetail?->installment_amount ?? 0),
            'sisa_pinjaman_sebelum' => (float) ($loanDetail?->remaining_before ?? 0),
            'sisa_pinjaman_setelah' => (float) ($loanDetail?->remaining_after ?? 0),
            'potongan_lain' => (float) $this->potongan_lain,
            'potongan_absensi' => (float) $this->potongan_absensi,
            'potongan_tunj_pt' => (float) $this->potongan_tunj_pt,
            'potongan_jht_pens' => (float) $this->potongan_jht_pens,
            'potongan_bpjs_karyawan' => (float) $this->potongan_bpjs_karyawan,
            'potongan_tunj_pph21' => (float) $this->potongan_tunj_pph21,
            'gross_i' => $grossI,
            'gross_ii' => $grossII,
            'automatic_income' => $automaticIncome,
            'automatic_deduction' => $automaticDeduction,
            'automatic_take_home_pay' => (float) $this->take_home_pay,
            'manual_income_total' => $manualIncome,
            'manual_deduction_total' => $manualDeduction,
            'total_income' => $totalIncome,
            'gross_income' => $automaticIncome,
            'total_deduction' => $totalDeduction,
            'take_home_pay' => round($totalIncome - $totalDeduction, 2),
            'manual_adjustments' => $adjustments->map(fn ($adjustment) => [
                'id' => $adjustment->id,
                'type' => $adjustment->type,
                'component_name' => $adjustment->component_name,
                'amount' => (float) $adjustment->amount,
            ])->values(),
            'is_editable' => $this->status === 'draft' && ! $isCompleted,
            'status' => $this->status,
        ];
    }
}
