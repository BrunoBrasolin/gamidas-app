<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Rooms of the attention game, shared by the household. Anyone with the
     * room code plays from the public page under a nickname; each player
     * answers every challenge once. Players are identified by a random token
     * kept on their phone (only its hash is stored); nothing else about them
     * is recorded.
     */
    public function up(): void
    {
        Schema::create('attention_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 120);
            $table->string('code', 8)->unique();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['household_id', 'created_at']);
        });

        Schema::create('attention_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attention_game_id')->constrained()->cascadeOnDelete();
            $table->string('nickname', 24);
            $table->char('token_hash', 64)->unique();
            $table->unsignedInteger('score')->default(0);
            $table->unsignedTinyInteger('correct_rounds')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['attention_game_id', 'nickname']);
        });

        Schema::create('attention_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attention_player_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->boolean('correct');
            $table->unsignedTinyInteger('accuracy');
            $table->unsignedSmallInteger('hits');
            $table->unsignedSmallInteger('errors');
            $table->unsignedSmallInteger('misses');
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('reaction_ms')->nullable();
            $table->string('guess', 20)->nullable();
            $table->boolean('guess_correct');
            $table->unsignedSmallInteger('performance_points');
            $table->unsignedSmallInteger('points');
            $table->json('details')->nullable();
            $table->timestamps();

            $table->unique(['attention_player_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attention_rounds');
        Schema::dropIfExists('attention_players');
        Schema::dropIfExists('attention_games');
    }
};
