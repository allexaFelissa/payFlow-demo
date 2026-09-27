<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $divisions = [
            'Delima pinglogistra ( DPL )' => 'DPL',
            'Toyota Logistra Pingloka Indonesia ( TOPI )' => 'TOPI',
            'Satya Ragam Korporindo - Pusat/Cikarang ( SRK / KORP )' => 'SRK / KORP',
            'Satya Ragam Korporindo - Semarang ( SRK / KORP )' => 'SRK / KORP',
            'Satya Ragam Truxpress - Pulo Mas ( SRT PM )' => 'SRT PM',
            'Satya Ragam Truxpress - Cikarang ( SRT CKRG )' => 'SRT CKRG',
            'Satya Ragam Truxpress - Semarang ( SRT SMRG )' => 'SRT SMRG',
            'Satya Ragam Truxpress - Surabaya ( SRT SBY )' => 'SRT SBY',
        ];

        foreach ($divisions as $old => $new) {
            DB::table('employees')->where('department', $old)->update(['department' => $new]);
        }
    }

    public function down(): void
    {
        // The two SRK / KORP locations cannot be separated again after consolidation.
    }
};
