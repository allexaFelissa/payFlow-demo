<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'bpjs_kes_pt',
        'jkk_jkm_pt',
        'jht_pens_pt',
        'tunj_bpjs_beban_pt',
        'potongan_tk',
        'potongan_kes',
        'potongan_tunj_pt',
        'potongan_jht_pens',
        'potongan_bpjs_karyawan',
        'gross_i',
        'gross_ii',
        'gross_income',
        'total_deduction',
    ];

    public function up(): void
    {
        Schema::table('payroll_calculations', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->decimal($column, 17, 4)->default(0)->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_calculations', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->decimal($column, 15, 2)->default(0)->change();
            }
        });
    }
};
