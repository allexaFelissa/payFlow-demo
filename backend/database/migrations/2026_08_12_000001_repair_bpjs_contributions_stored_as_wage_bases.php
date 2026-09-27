<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $configuration = DB::table('master_baseline_payroll')->where('is_active', true)->orderByDesc('id')->first();
        if (! $configuration) {
            return;
        }

        $tkCombinedRate = (float) $configuration->jht_pens
            + (float) $configuration->jkk_jkm
            + (float) $configuration->potgn_tk;
        $healthCombinedRate = (float) $configuration->bpjs_p
            + (float) $configuration->potgn_kes;

        if ($tkCombinedRate <= 0 || $healthCombinedRate <= 0) {
            return;
        }

        DB::table('master_data_komponen_gaji')
            ->where('dasar_perhitungan_bpjs_tk', '>', 0)
            ->where('dasar_perhitungan_bpjs_kes', '>', 0)
            ->orderBy('id')
            ->chunkById(100, function ($baselines) use ($tkCombinedRate, $healthCombinedRate) {
                foreach ($baselines as $baseline) {
                    $tkBase = (float) $baseline->dasar_perhitungan_bpjs_tk / ($tkCombinedRate / 100);
                    $healthBase = (float) $baseline->dasar_perhitungan_bpjs_kes / ($healthCombinedRate / 100);
                    $tolerance = max(1, $healthBase * .001);

                    // Both stored values were contribution totals calculated from one wage
                    // base. Ordinary wage bases do not produce this rate-specific ratio.
                    if (abs($tkBase - $healthBase) > $tolerance) {
                        continue;
                    }

                    $wageBase = round($healthBase, 2);
                    DB::table('master_data_komponen_gaji')->where('id', $baseline->id)->update([
                        'dasar_perhitungan_bpjs_tk' => $wageBase,
                        'dasar_perhitungan_bpjs_kes' => $wageBase,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // The original contribution totals were invalid and cannot be restored safely.
    }
};
