<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_period_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_period_id')->constrained()->cascadeOnDelete();
            $table->date('attendance_date');
            $table->timestamps();
            $table->unique(['attendance_period_id', 'attendance_date'], 'attendance_period_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_period_dates');
    }
};
