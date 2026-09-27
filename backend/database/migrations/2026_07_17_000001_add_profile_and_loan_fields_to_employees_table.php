<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('gender', 20)->nullable();
            $table->date('birth_date')->nullable();
            $table->text('address')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 50)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account_number', 100)->nullable();
            $table->string('tax_number', 100)->nullable();
            $table->decimal('loan_amount', 15, 2)->default(0);
            $table->decimal('loan_balance', 15, 2)->default(0);
            $table->decimal('loan_installment', 15, 2)->default(0);
            $table->date('loan_start_date')->nullable();
            $table->text('loan_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'gender', 'birth_date', 'address', 'emergency_contact_name', 'emergency_contact_phone',
                'bank_name', 'bank_account_number', 'tax_number', 'loan_amount', 'loan_balance',
                'loan_installment', 'loan_start_date', 'loan_notes',
            ]);
        });
    }
};
