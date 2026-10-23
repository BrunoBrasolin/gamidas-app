<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('timer_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->string('status', 10);
            // Instants are stored in UTC (App\Casts\UtcDateTime).
            $table->dateTime('started_at');
            $table->dateTime('last_resumed_at')->nullable();
            $table->dateTime('paused_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->unsignedInteger('accumulated_seconds')->default(0);
            // 1 while running, null otherwise: the unique index below allows a
            // single running session per user on every database driver.
            $table->unsignedTinyInteger('running_lock')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'running_lock']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timer_sessions');
    }
};
