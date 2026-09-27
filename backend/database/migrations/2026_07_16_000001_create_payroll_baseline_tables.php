<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_data_komponen_gaji', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('employees')->cascadeOnDelete();
            $table->decimal('gaji_pokok', 15, 2)->default(0);
            $table->decimal('tunj_antar_cabang', 15, 2)->default(0);
            $table->decimal('tunj_komunikasi', 15, 2)->default(0);
            $table->decimal('tunj_kost', 15, 2)->default(0);
            $table->decimal('tunj_jabatan', 15, 2)->default(0);
            $table->decimal('dasar_perhitungan_bpjs_tk', 15, 2)->default(0);
            $table->decimal('dasar_perhitungan_bpjs_kes', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('master_baseline_payroll', function (Blueprint $table) {
            $table->id();
            $table->decimal('jht_pens', 6, 2)->default(5.70);
            $table->decimal('jkk_jkm', 6, 2)->default(0.54);
            $table->decimal('bpjs_p', 6, 2)->default(4.00);
            $table->decimal('tunj_bpjs', 6, 2)->default(10.24);
            $table->decimal('potgn_tk', 6, 2)->default(3.00);
            $table->decimal('tunj_prshan', 6, 2)->default(10.24);
            $table->decimal('potgn_kes', 6, 2)->default(1.00);
            $table->decimal('lembur_multiplier', 6, 2)->default(1.50);
            $table->decimal('insentif_default', 15, 2)->default(10.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_baseline_payroll');
        Schema::dropIfExists('master_data_komponen_gaji');
    }
};
