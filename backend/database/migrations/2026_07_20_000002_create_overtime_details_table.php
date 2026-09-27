<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('overtime_id')->constrained()->cascadeOnDelete();
            $table->date('attendance_date');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->integer('duration_minutes')->default(0);
            $table->timestamps();

            $table->unique(['overtime_id', 'attendance_date'], 'overtime_detail_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_details');
    }
};
