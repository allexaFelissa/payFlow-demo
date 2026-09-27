<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_data_komponen_gaji', function (Blueprint $table) {
            $table->dropColumn('tunj_pph21');
        });
    }

    public function down(): void
    {
        Schema::table('master_data_komponen_gaji', function (Blueprint $table) {
            $table->decimal('tunj_pph21', 15, 2)->default(0);
        });
    }
};
