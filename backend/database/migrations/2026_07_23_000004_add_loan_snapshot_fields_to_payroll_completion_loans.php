<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_completion_loans', function (Blueprint $table) {
            $table->decimal('loan_amount_before', 15, 2)->default(0);
            $table->decimal('loan_installment_before', 15, 2)->default(0);
            $table->date('loan_start_date_before')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_completion_loans', function (Blueprint $table) {
            $table->dropColumn(['loan_amount_before', 'loan_installment_before', 'loan_start_date_before']);
        });
    }
};
