<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_periods', function (Blueprint $table) {
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamp('imported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_periods', function (Blueprint $table) {
            $table->dropColumn(['starts_on', 'ends_on', 'imported_at']);
        });
    }
};
