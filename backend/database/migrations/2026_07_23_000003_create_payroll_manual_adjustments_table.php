<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_manual_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_calculation_id')->constrained('payroll_calculations')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_period_id')->constrained('attendance_periods')->cascadeOnDelete();
            $table->enum('type', ['income', 'deduction']);
            $table->string('component_name', 150);
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_manual_adjustments');
    }
};
