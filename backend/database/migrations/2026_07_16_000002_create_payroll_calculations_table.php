<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained('attendance_periods')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            foreach (['gaji_pokok', 'tunj_antar_cabang', 'tunj_komunikasi', 'tunj_kost', 'tunj_jabatan'] as $column) {
                $table->decimal($column, 15, 2)->default(0);
            }
            foreach (['hadir', 'izin', 'sakit', 'cuti', 'alpha', 'late_minutes'] as $column) {
                $table->integer($column)->default(0);
            }
            $table->decimal('overtime_hours', 10, 2)->default(0);
            foreach (['overtime_amount', 'insentif', 'potongan_tk', 'potongan_kes', 'potongan_alpha', 'potongan_terlambat', 'potongan_pinjaman', 'potongan_lain', 'gross_income', 'total_deduction', 'take_home_pay'] as $column) {
                $table->decimal($column, 15, 2)->default(0);
            }
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->unique(['payroll_period_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_calculations');
    }
};
