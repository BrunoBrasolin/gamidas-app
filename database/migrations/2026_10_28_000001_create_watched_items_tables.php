<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * What each user watched, recorded by the Jellyfin webhook. A user has at
     * most one Jellyfin connection, identified by the SHA-256 of its token
     * (the token itself is shown once and never stored). An item counts once
     * per local day, so a webhook sent twice records a single entry.
     */
    public function up(): void
    {
        Schema::create('jellyfin_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->dateTime('last_received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('watched_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('title', 255);
            $table->string('series_name', 255)->nullable();
            $table->unsignedSmallInteger('season_number')->nullable();
            $table->unsignedSmallInteger('episode_number')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedInteger('runtime_seconds')->nullable();
            $table->string('jellyfin_item_id', 64)->nullable();
            $table->dateTime('watched_at');
            $table->date('watched_on');
            $table->timestamps();

            $table->unique(['user_id', 'jellyfin_item_id', 'watched_on']);
            $table->index(['user_id', 'watched_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('watched_items');
        Schema::dropIfExists('jellyfin_connections');
    }
};
