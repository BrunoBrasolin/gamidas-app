<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Each user's Steam account (at most one per user, and a Steam account
     * belongs to one user), its library with playtime, the achievements of
     * each game and the minutes played per game and day. Steam reports only
     * total playtime, so the daily history is the difference between two
     * syncs, recorded from the first sync on. Everything belongs to the
     * user who connected the account.
     */
    public function up(): void
    {
        Schema::create('steam_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('steam_id', 20)->unique();
            $table->string('persona_name', 255)->nullable();
            $table->string('profile_url', 255)->nullable();
            $table->string('avatar_url', 255)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->boolean('public_profile')->default(true);
            $table->boolean('public_games')->default(true);
            $table->unsignedTinyInteger('persona_state')->default(0);
            $table->unsignedInteger('playing_app_id')->nullable();
            $table->string('playing_name', 255)->nullable();
            $table->dateTime('account_created_at')->nullable();
            $table->dateTime('last_logoff_at')->nullable();
            $table->unsignedSmallInteger('level')->nullable();
            $table->unsignedInteger('xp')->nullable();
            $table->unsignedInteger('xp_level_start')->nullable();
            $table->unsignedInteger('xp_to_next_level')->nullable();
            $table->unsignedInteger('badges_count')->nullable();
            // Last attempt, and last time the library's playtime was read.
            $table->dateTime('checked_at')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->string('sync_error', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('steam_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('steam_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('app_id');
            $table->string('name', 255);
            $table->string('icon_hash', 64)->nullable();
            $table->unsignedInteger('playtime_minutes')->default(0);
            $table->unsignedInteger('playtime_2weeks_minutes')->default(0);
            $table->unsignedInteger('playtime_windows_minutes')->default(0);
            $table->unsignedInteger('playtime_mac_minutes')->default(0);
            $table->unsignedInteger('playtime_linux_minutes')->default(0);
            $table->unsignedInteger('playtime_deck_minutes')->default(0);
            $table->dateTime('last_played_at')->nullable();
            $table->dateTime('added_at');
            $table->dateTime('removed_at')->nullable();
            $table->unsignedSmallInteger('achievements_total')->nullable();
            $table->unsignedSmallInteger('achievements_unlocked')->nullable();
            $table->dateTime('achievements_synced_at')->nullable();
            $table->unsignedInteger('achievements_playtime_minutes')->nullable();
            $table->timestamps();

            $table->unique(['steam_account_id', 'app_id']);
            $table->index(['steam_account_id', 'last_played_at']);
        });

        Schema::create('steam_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('steam_game_id')->constrained()->cascadeOnDelete();
            $table->string('api_name', 128);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('icon_url', 255)->nullable();
            $table->string('icon_gray_url', 255)->nullable();
            $table->boolean('hidden')->default(false);
            // Share of Steam players who unlocked it, in hundredths of a percent.
            $table->unsignedSmallInteger('global_percent_bp')->nullable();
            $table->boolean('achieved')->default(false);
            $table->dateTime('unlocked_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['steam_game_id', 'api_name']);
            $table->index(['steam_game_id', 'unlocked_at']);
        });

        Schema::create('steam_playtime_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('steam_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('steam_game_id')->constrained()->cascadeOnDelete();
            $table->date('played_on');
            $table->unsignedInteger('minutes');
            $table->timestamps();

            $table->unique(['steam_game_id', 'played_on']);
            $table->index(['steam_account_id', 'played_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('steam_playtime_days');
        Schema::dropIfExists('steam_achievements');
        Schema::dropIfExists('steam_games');
        Schema::dropIfExists('steam_accounts');
    }
};
