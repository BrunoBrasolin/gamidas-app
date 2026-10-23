<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An optional daily goal, in seconds, painted green or red on the sheet.
     */
    public function up(): void
    {
        Schema::table('time_categories', function (Blueprint $table) {
            $table->unsignedInteger('daily_goal_seconds')->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('time_categories', function (Blueprint $table) {
            $table->dropColumn('daily_goal_seconds');
        });
    }
};
