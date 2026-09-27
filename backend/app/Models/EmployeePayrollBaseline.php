<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayrollBaseline extends Model
{
    protected $table = 'master_data_komponen_gaji';

    protected $fillable = ['employee_id', 'pt', 'gaji_pokok', 'uang_harian', 'tunj_antar_cabang', 'tunj_komunikasi', 'tunj_kost', 'tunj_jabatan', 'thr', 'dasar_perhitungan_bpjs_tk', 'dasar_perhitungan_bpjs_kes', 'potongan_alpha', 'incentive_eligible'];

    protected function casts(): array
    {
        return [
            ...array_fill_keys(['gaji_pokok', 'uang_harian', 'tunj_antar_cabang', 'tunj_komunikasi', 'tunj_kost', 'tunj_jabatan', 'thr', 'dasar_perhitungan_bpjs_tk', 'dasar_perhitungan_bpjs_kes', 'potongan_alpha'], 'decimal:2'),
            'incentive_eligible' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected static function booted(): void
    {
        static::creating(function (EmployeePayrollBaseline $baseline) {
            if (! $baseline->pt && $baseline->employee_id) {
                $baseline->pt = Employee::find($baseline->employee_id)?->primaryPayrollPt()
                    ?? config('employees.pts.0');
            }
        });
    }
}
