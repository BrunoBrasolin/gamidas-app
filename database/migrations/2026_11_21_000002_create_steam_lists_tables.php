<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Two lists each user keeps of their Steam games: the tier list (each
     * game in a tier, S to F, in the order the user arranged) and the games
     * to finish ("para zerar"), each waiting, being played or finished. They
     * belong to the user, not to the Steam account, and refer to games by
     * app id with the name they had, so disconnecting and connecting again
     * keeps them.
     */
    public function up(): void
    {
        Schema::create('steam_tier_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('app_id');
            $table->string('name', 255);
            $table->char('tier', 1);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'app_id']);
            $table->index(['user_id', 'tier', 'position']);
        });

        Schema::create('steam_backlog_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('app_id');
            $table->string('name', 255);
            $table->string('status', 16);
            $table->unsignedInteger('position')->default(0);
            // Steam playtime in minutes when the game entered the list and when it was finished.
            $table->unsignedInteger('playtime_at_add')->default(0);
            $table->unsignedInteger('playtime_at_completion')->nullable();
            $table->dateTime('added_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'app_id']);
            $table->index(['user_id', 'status', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('steam_backlog_entries');
        Schema::dropIfExists('steam_tier_entries');
    }
};
