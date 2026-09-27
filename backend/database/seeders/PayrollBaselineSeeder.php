<?php

namespace Database\Seeders;

use App\Models\PayrollBaseline;
use Illuminate\Database\Seeder;

class PayrollBaselineSeeder extends Seeder
{
    public function run(): void
    {
        if (! PayrollBaseline::active()->exists()) {
            PayrollBaseline::create(['jht_pens' => 5.70, 'jkk_jkm' => 0.54, 'bpjs_p' => 4.00, 'tunj_bpjs' => 10.24, 'potgn_tk' => 3.00, 'tunj_prshan' => 10.24, 'potgn_kes' => 1.00, 'lembur_multiplier' => 1.50, 'insentif_default' => 10.00, 'is_active' => true]);
        }
    }
}
