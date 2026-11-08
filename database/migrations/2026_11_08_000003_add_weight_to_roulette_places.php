<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * How much more likely a place is to be drawn: the wheel gives each place
     * a slice proportional to its weight. Existing places keep the same odds.
     */
    public function up(): void
    {
        Schema::table('roulette_places', function (Blueprint $table) {
            $table->unsignedTinyInteger('weight')->default(1)->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roulette_places', function (Blueprint $table) {
            $table->dropColumn('weight');
        });
    }
};
