<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Time is tracked per category (a job or project) and day, replacing the
     * earlier task / timer / entry / daily-note tables, which are dropped.
     */
    public function up(): void
    {
        Schema::dropIfExists('time_entries');
        Schema::dropIfExists('timer_sessions');
        Schema::dropIfExists('daily_notes');
        Schema::dropIfExists('tasks');

        Schema::create('time_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('time_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('time_category_id')->constrained('time_categories')->restrictOnDelete();
            $table->date('log_date');
            $table->unsignedInteger('seconds')->default(0);
            $table->mediumText('note')->nullable();
            $table->timestamps();

            // One cell per category and day.
            $table->unique(['time_category_id', 'log_date']);
            $table->index(['user_id', 'log_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_logs');
        Schema::dropIfExists('time_categories');
    }
};
