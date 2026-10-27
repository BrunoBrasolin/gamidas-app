<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Where each person of the household stopped an item they have not
     * finished, from the Jellyfin webhook's Playback Start and Stop events
     * (one row per person and item, removed when they finish it). Both tables
     * keep the external ids Jellyfin knows the item by (tmdb, imdb, tvdb…),
     * used to look up its poster, cast and synopsis.
     */
    public function up(): void
    {
        Schema::create('watch_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('viewer', 100)->default('');
            $table->string('jellyfin_item_id', 64);
            $table->string('type', 16);
            $table->string('title', 255);
            $table->string('series_name', 255)->nullable();
            $table->unsignedSmallInteger('season_number')->nullable();
            $table->unsignedSmallInteger('episode_number')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedInteger('runtime_seconds')->nullable();
            $table->json('provider_ids')->nullable();
            $table->unsignedInteger('position_seconds')->default(0);
            $table->dateTime('playing_since')->nullable();
            $table->dateTime('last_played_at');
            $table->timestamps();

            $table->unique(['household_id', 'viewer', 'jellyfin_item_id']);
            $table->index(['household_id', 'last_played_at']);
        });

        Schema::table('watched_items', function (Blueprint $table) {
            $table->json('provider_ids')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('watched_items', function (Blueprint $table) {
            $table->dropColumn('provider_ids');
        });

        Schema::dropIfExists('watch_progress');
    }
};
