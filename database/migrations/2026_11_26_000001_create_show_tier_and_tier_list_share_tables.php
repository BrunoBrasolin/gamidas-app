<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Each user's tier list of the household's shows (each show in a tier, S
     * to F, in the order the user arranged), and the public links to a
     * user's tier lists: a list is public only while it has a link, found by
     * its random token.
     */
    public function up(): void
    {
        Schema::create('show_tier_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('show_id')->constrained()->cascadeOnDelete();
            $table->char('tier', 1);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'show_id']);
            $table->index(['user_id', 'tier', 'position']);
        });

        Schema::create('tier_list_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('token', 40)->unique();
            $table->timestamps();

            $table->unique(['user_id', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tier_list_shares');
        Schema::dropIfExists('show_tier_entries');
    }
};
