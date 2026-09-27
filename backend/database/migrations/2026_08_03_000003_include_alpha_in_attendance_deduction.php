<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('payroll_calculations')
            ->where('potongan_alpha', '>', 0)
            ->whereColumn('potongan_absensi', 'potongan_terlambat')
            ->update([
                'potongan_absensi' => DB::raw('potongan_absensi + potongan_alpha'),
            ]);
    }

    public function down(): void
    {
        DB::table('payroll_calculations')
            ->where('potongan_alpha', '>', 0)
            ->whereRaw('potongan_absensi = potongan_terlambat + potongan_alpha')
            ->update([
                'potongan_absensi' => DB::raw('potongan_absensi - potongan_alpha'),
            ]);
    }
};
