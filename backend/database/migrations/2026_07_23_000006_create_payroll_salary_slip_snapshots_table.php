<?php

use App\Services\Payroll\PayrollSalarySlipService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_salary_slip_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_completion_id')->constrained('payroll_completions')->cascadeOnDelete();
            $table->foreignId('payroll_calculation_id')->constrained('payroll_calculations')->cascadeOnDelete();
            $table->foreignId('attendance_period_id')->constrained('attendance_periods')->cascadeOnDelete();
            $table->string('pt', 100);
            $table->string('employee_number');
            $table->string('employee_name');
            $table->json('payload');
            $table->timestamps();
            $table->unique('payroll_calculation_id');
            $table->index(['attendance_period_id', 'pt', 'employee_name'], 'salary_slip_period_pt_name_index');
        });

        if (DB::table('payroll_completions')->exists()) {
            app(PayrollSalarySlipService::class)->backfillExistingCompletions();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_salary_slip_snapshots');
    }
};
