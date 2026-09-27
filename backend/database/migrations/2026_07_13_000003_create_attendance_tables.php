<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_periods', function (Blueprint $t) {
            $t->id();
            $t->smallInteger('month');
            $t->smallInteger('year');
            $t->string('status')->default('draft');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['month', 'year']);
        });
        Schema::create('attendance_period_employees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('attendance_period_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->decimal('monthly_overtime_hours', 8, 2)->default(0);
            $t->timestamps();
            $t->unique(['attendance_period_id', 'employee_id'], 'attendance_period_employee_unique');
        });
        Schema::create('attendance_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('attendance_period_id')->constrained()->cascadeOnDelete();
            $t->date('attendance_date');
            $t->string('status', 1);
            $t->text('remarks')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'attendance_date', 'attendance_period_id'], 'attendance_no_duplicates');
            $t->index(['attendance_period_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_period_employees');
        Schema::dropIfExists('attendance_periods');
    }
};
