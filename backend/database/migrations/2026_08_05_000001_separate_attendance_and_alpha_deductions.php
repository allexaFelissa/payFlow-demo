<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('payroll_calculations')->update([
            'potongan_absensi' => DB::raw('potongan_terlambat'),
        ]);
    }

    public function down(): void
    {
        DB::table('payroll_calculations')->update([
            'potongan_absensi' => DB::raw('potongan_alpha'),
        ]);
    }
};
