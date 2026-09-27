<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('employees')
            ->where('loan_balance', '<=', 0)
            ->where(function ($query) {
                $query->where('loan_amount', '>', 0)
                    ->orWhere('loan_installment', '>', 0)
                    ->orWhereNotNull('loan_start_date')
                    ->orWhereNotNull('loan_notes');
            })
            ->update([
                'loan_amount' => 0,
                'loan_balance' => 0,
                'loan_installment' => 0,
                'loan_start_date' => null,
                'loan_notes' => null,
            ]);
    }

    public function down(): void
    {
        // Riwayat pinjaman tetap tersimpan pada transaksi payroll dan data master yang
        // telah dibersihkan tidak dapat direkonstruksi dengan aman saat rollback.
    }
};
