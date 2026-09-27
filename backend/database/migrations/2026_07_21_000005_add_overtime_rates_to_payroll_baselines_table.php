<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_baseline_payroll', function (Blueprint $table) {
            $table->decimal('lembur_hari_kerja_rate', 15, 2)->default(10000);
            $table->decimal('lembur_hari_libur_multiplier', 6, 2)->default(2);
        });
    }

    public function down(): void
    {
        Schema::table('master_baseline_payroll', function (Blueprint $table) {
            $table->dropColumn(['lembur_hari_kerja_rate', 'lembur_hari_libur_multiplier']);
        });
    }
};
