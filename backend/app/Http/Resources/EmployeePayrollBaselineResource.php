<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeePayrollBaselineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $baseline = $this->payrollBaseline;

        return [
            'id' => $this->id.'-'.$this->baseline_pt,
            'employee_id' => $this->id,
            'employee_name' => $this->name,
            'employee_number' => $this->employee_number,
            'tax_number' => $this->tax_number,
            'pt' => $this->baseline_pt,
            'title' => $this->position,
            'family_status' => $this->family_status,
            'gaji_pokok' => (float) ($baseline?->gaji_pokok ?? 0),
            'uang_harian' => (float) ($baseline?->uang_harian ?? 0),
            'tunj_antar_cabang' => (float) ($baseline?->tunj_antar_cabang ?? 0),
            'tunj_komunikasi' => (float) ($baseline?->tunj_komunikasi ?? 0),
            'tunj_kost' => (float) ($baseline?->tunj_kost ?? 0),
            'tunj_jabatan' => (float) ($baseline?->tunj_jabatan ?? 0),
            'thr' => (float) ($baseline?->thr ?? 0),
            'dasar_perhitungan_bpjs_tk' => (float) ($baseline?->dasar_perhitungan_bpjs_tk ?? 0),
            'dasar_perhitungan_bpjs_kes' => (float) ($baseline?->dasar_perhitungan_bpjs_kes ?? 0),
            'potongan_alpha' => (float) ($baseline?->potongan_alpha ?? 0),
            'incentive_eligible' => (bool) ($baseline?->incentive_eligible ?? true),
        ];
    }
}
