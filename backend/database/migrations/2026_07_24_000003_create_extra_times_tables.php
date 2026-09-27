<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extra_times', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_period_id')->constrained('attendance_periods')->cascadeOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'payroll_period_id'], 'extra_times_employee_period_unique');
            $table->index('payroll_period_id');
        });

        Schema::create('extra_time_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extra_time_id')->constrained()->cascadeOnDelete();
            $table->date('attendance_date');
            $table->boolean('is_holiday')->default(false);
            $table->decimal('amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['extra_time_id', 'attendance_date'], 'extra_time_detail_date_unique');
        });

        Schema::table('payroll_calculations', function (Blueprint $table) {
            $table->decimal('extra_time_amount', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_calculations', function (Blueprint $table) {
            $table->dropColumn('extra_time_amount');
        });

        Schema::dropIfExists('extra_time_details');
        Schema::dropIfExists('extra_times');
    }
};
