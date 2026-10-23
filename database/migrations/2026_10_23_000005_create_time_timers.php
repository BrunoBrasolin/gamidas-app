<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A stopwatch per category. The unique category column is what keeps a
     * category to a single timer; a null started_at means it is paused.
     */
    public function up(): void
    {
        Schema::create('time_timers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('time_category_id')->unique()->constrained('time_categories')->cascadeOnDelete();
            $table->unsignedInteger('accumulated_seconds')->default(0);
            $table->dateTime('started_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_timers');
    }
};
