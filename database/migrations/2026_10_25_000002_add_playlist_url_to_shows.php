<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Optional link to the show's Spotify playlist.
     */
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->string('playlist_url', 2048)->nullable()->after('photos_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropColumn('playlist_url');
        });
    }
};
