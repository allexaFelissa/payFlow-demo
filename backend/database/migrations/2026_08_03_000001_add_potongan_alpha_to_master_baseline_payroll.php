<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_baseline_payroll', function (Blueprint $table) {
            $table->decimal('potongan_alpha', 15, 2)->default(0)->after('potgn_kes');
        });
    }

    public function down(): void
    {
        Schema::table('master_baseline_payroll', function (Blueprint $table) {
            $table->dropColumn('potongan_alpha');
        });
    }
};
