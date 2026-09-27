<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_period_id')->constrained()->cascadeOnDelete();
            $table->string('pt', 100);
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['attendance_period_id', 'pt']);
        });

        Schema::create('payroll_completion_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_completion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->decimal('deduction_amount', 15, 2);
            $table->decimal('loan_balance_before', 15, 2);
            $table->text('loan_notes_before')->nullable();
            $table->timestamps();
            $table->unique(['payroll_completion_id', 'employee_id'], 'payroll_completion_loan_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_completion_loans');
        Schema::dropIfExists('payroll_completions');
    }
};
