<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->json('pts')->nullable();
        });

        DB::table('employees')
            ->whereNotNull('branch')
            ->where('branch', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($employees) {
                foreach ($employees as $employee) {
                    DB::table('employees')->where('id', $employee->id)->update([
                        'pts' => json_encode([$employee->branch]),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('pts');
        });
    }
};
