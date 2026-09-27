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
            $table->boolean('incentive_eligible')->default(true)->after('potongan_alpha');
        });

        DB::table('master_data_komponen_gaji')
            ->where('pt', 'SRT CKRG')
            ->whereIn('employee_id', DB::table('employees')->select('id')->where('employee_number', '1711121'))
            ->update(['incentive_eligible' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('master_data_komponen_gaji', function (Blueprint $table) {
            $table->dropColumn('incentive_eligible');
        });
    }
};
