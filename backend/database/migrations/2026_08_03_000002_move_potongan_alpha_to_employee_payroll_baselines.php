<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_data_komponen_gaji', function (Blueprint $table) {
            $table->decimal('potongan_alpha', 15, 2)->default(0);
        });

        $globalAlphaDeduction = DB::table('master_baseline_payroll')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->value('potongan_alpha') ?? 0;

        DB::table('master_data_komponen_gaji')->update([
            'potongan_alpha' => $globalAlphaDeduction,
        ]);

        Schema::table('master_baseline_payroll', function (Blueprint $table) {
            $table->dropColumn('potongan_alpha');
        });
    }

    public function down(): void
    {
        Schema::table('master_baseline_payroll', function (Blueprint $table) {
            $table->decimal('potongan_alpha', 15, 2)->default(0);
        });

        $employeeAlphaDeduction = DB::table('master_data_komponen_gaji')
            ->max('potongan_alpha') ?? 0;

        DB::table('master_baseline_payroll')
            ->where('is_active', true)
            ->update(['potongan_alpha' => $employeeAlphaDeduction]);

        Schema::table('master_data_komponen_gaji', function (Blueprint $table) {
            $table->dropColumn('potongan_alpha');
        });
    }
};
