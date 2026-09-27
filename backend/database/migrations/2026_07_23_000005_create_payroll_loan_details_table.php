<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_loan_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_calculation_id')->unique()->constrained('payroll_calculations')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->decimal('loan_amount', 15, 2)->default(0);
            $table->decimal('installment_amount', 15, 2)->default(0);
            $table->decimal('deducted_amount', 15, 2)->default(0);
            $table->decimal('remaining_before', 15, 2)->default(0);
            $table->decimal('remaining_after', 15, 2)->default(0);
            $table->date('loan_start_date')->nullable();
            $table->text('loan_notes')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('payroll_calculations')
            ->join('employees', 'employees.id', '=', 'payroll_calculations.employee_id')
            ->select([
                'payroll_calculations.id as payroll_calculation_id',
                'payroll_calculations.employee_id',
                'payroll_calculations.potongan_pinjaman',
                'employees.loan_amount',
                'employees.loan_balance',
                'employees.loan_installment',
                'employees.loan_start_date',
                'employees.loan_notes',
            ])
            ->orderBy('payroll_calculations.id')
            ->get()
            ->each(function ($row) use ($now) {
                $deducted = round(min((float) $row->loan_balance, (float) $row->potongan_pinjaman), 2);
                DB::table('payroll_loan_details')->insert([
                    'payroll_calculation_id' => $row->payroll_calculation_id,
                    'employee_id' => $row->employee_id,
                    'loan_amount' => $row->loan_amount,
                    'installment_amount' => $row->loan_installment,
                    'deducted_amount' => $deducted,
                    'remaining_before' => $row->loan_balance,
                    'remaining_after' => round(max(0, (float) $row->loan_balance - $deducted), 2),
                    'loan_start_date' => $row->loan_start_date,
                    'loan_notes' => $row->loan_notes,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_loan_details');
    }
};
