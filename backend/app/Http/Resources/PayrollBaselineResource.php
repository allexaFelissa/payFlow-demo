<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollBaselineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'jht_pens' => (float) $this->jht_pens, 'jkk_jkm' => (float) $this->jkk_jkm, 'bpjs_p' => (float) $this->bpjs_p, 'tunj_bpjs' => (float) $this->tunj_bpjs, 'potgn_tk' => (float) $this->potgn_tk, 'tunj_prshan' => (float) $this->tunj_prshan, 'potgn_kes' => (float) $this->potgn_kes, 'lembur_hari_kerja_rate' => (float) $this->lembur_hari_kerja_rate, 'lembur_hari_libur_multiplier' => (float) $this->lembur_hari_libur_multiplier, 'insentif_default' => (float) $this->insentif_default, 'is_active' => $this->is_active];
    }
}
