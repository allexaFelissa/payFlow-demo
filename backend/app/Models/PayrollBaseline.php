<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PayrollBaseline extends Model
{
    protected $table = 'master_baseline_payroll';

    protected $fillable = ['jht_pens', 'jkk_jkm', 'bpjs_p', 'tunj_bpjs', 'potgn_tk', 'tunj_prshan', 'potgn_kes', 'lembur_multiplier', 'lembur_hari_kerja_rate', 'lembur_hari_libur_multiplier', 'insentif_default', 'is_active'];

    protected function casts(): array
    {
        return ['jht_pens' => 'decimal:2', 'jkk_jkm' => 'decimal:2', 'bpjs_p' => 'decimal:2', 'tunj_bpjs' => 'decimal:2', 'potgn_tk' => 'decimal:2', 'tunj_prshan' => 'decimal:2', 'potgn_kes' => 'decimal:2', 'lembur_multiplier' => 'decimal:2', 'lembur_hari_kerja_rate' => 'decimal:2', 'lembur_hari_libur_multiplier' => 'decimal:2', 'insentif_default' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
