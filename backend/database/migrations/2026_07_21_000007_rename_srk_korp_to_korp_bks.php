<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('employees')->where('branch', 'SRK / KORP')->update(['branch' => 'KORP BKS']);
        DB::table('employees')->where('department', 'SRK / KORP')->update(['department' => 'KORP BKS']);
        DB::table('payroll_completions')->where('pt', 'SRK / KORP')->update(['pt' => 'KORP BKS']);
    }

    public function down(): void
    {
        DB::table('employees')->where('branch', 'KORP BKS')->update(['branch' => 'SRK / KORP']);
        DB::table('employees')->where('department', 'KORP BKS')->update(['department' => 'SRK / KORP']);
        DB::table('payroll_completions')->where('pt', 'KORP BKS')->update(['pt' => 'SRK / KORP']);
    }
};
