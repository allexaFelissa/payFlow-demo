<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_data_komponen_gaji', function (Blueprint $table) {
            $table->decimal('thr', 15, 2)->default(0);
        });

        Schema::table('payroll_calculations', function (Blueprint $table) {
            foreach (['tunj2', 'thr', 'tunj_pph21', 'bpjs_kes_pt', 'jkk_jkm_pt', 'jht_pens_pt', 'tunj_bpjs_beban_pt'] as $column) {
                $table->decimal($column, 15, 2)->default(0);
            }
            foreach (['potongan_absensi', 'potongan_tunj_pt', 'potongan_jht_pens', 'potongan_bpjs_karyawan', 'potongan_tunj_pph21', 'gross_i', 'gross_ii'] as $column) {
                $table->decimal($column, 15, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_calculations', function (Blueprint $table) {
            $table->dropColumn(['tunj2', 'thr', 'tunj_pph21', 'bpjs_kes_pt', 'jkk_jkm_pt', 'jht_pens_pt', 'tunj_bpjs_beban_pt', 'potongan_absensi', 'potongan_tunj_pt', 'potongan_jht_pens', 'potongan_bpjs_karyawan', 'potongan_tunj_pph21', 'gross_i', 'gross_ii']);
        });

        Schema::table('master_data_komponen_gaji', function (Blueprint $table) {
            $table->dropColumn('thr');
        });
    }
};
