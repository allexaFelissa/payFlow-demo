<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PayrollCalculation extends Model
{
    public const MAX_TOTAL_DEDUCTION = 12000000;

    protected $fillable = ['payroll_period_id', 'employee_id', 'pt', 'gaji_pokok', 'uang_harian', 'tunj_antar_cabang', 'tunj_komunikasi', 'tunj_kost', 'tunj_jabatan', 'tunj2', 'thr', 'tunj_pph21', 'bpjs_kes_pt', 'jkk_jkm_pt', 'jht_pens_pt', 'tunj_bpjs_beban_pt', 'hadir', 'izin', 'sakit', 'cuti', 'alpha', 'late_minutes', 'overtime_hours', 'overtime_amount', 'extra_time_amount', 'insentif', 'potongan_tk', 'potongan_kes', 'potongan_alpha', 'potongan_terlambat', 'potongan_pinjaman', 'potongan_lain', 'potongan_absensi', 'potongan_tunj_pt', 'potongan_jht_pens', 'potongan_bpjs_karyawan', 'potongan_tunj_pph21', 'gross_i', 'gross_ii', 'gross_income', 'total_deduction', 'take_home_pay', 'status'];

    protected function casts(): array
    {
        $casts = array_fill_keys(['gaji_pokok', 'uang_harian', 'tunj_antar_cabang', 'tunj_komunikasi', 'tunj_kost', 'tunj_jabatan', 'tunj2', 'thr', 'tunj_pph21', 'overtime_hours', 'overtime_amount', 'extra_time_amount', 'insentif', 'potongan_alpha', 'potongan_terlambat', 'potongan_pinjaman', 'potongan_lain', 'potongan_absensi', 'potongan_tunj_pph21', 'take_home_pay'], 'decimal:2');

        return array_merge($casts, array_fill_keys([
            'bpjs_kes_pt', 'jkk_jkm_pt', 'jht_pens_pt', 'tunj_bpjs_beban_pt',
            'potongan_tk', 'potongan_kes', 'potongan_tunj_pt', 'potongan_jht_pens',
            'potongan_bpjs_karyawan', 'gross_i', 'gross_ii', 'gross_income', 'total_deduction',
        ], 'decimal:4'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(AttendancePeriod::class, 'payroll_period_id');
    }

    public function manualAdjustments(): HasMany
    {
        return $this->hasMany(PayrollManualAdjustment::class);
    }

    public function loanDetail(): HasOne
    {
        return $this->hasOne(PayrollLoanDetail::class);
    }

    public function salarySlipSnapshot(): HasOne
    {
        return $this->hasOne(PayrollSalarySlipSnapshot::class);
    }

    public function cappedTotalDeduction(float $manualDeduction = 0): float
    {
        return round(min(self::MAX_TOTAL_DEDUCTION, (float) $this->total_deduction + $manualDeduction), 2);
    }
}
