<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Concerts attended by the household, each with its setlist and the
     * opening acts (with their own setlists). Setlists are ordered lists of
     * song titles. Imported shows have no creator.
     */
    public function up(): void
    {
        Schema::create('shows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('band', 120);
            $table->string('tour', 160)->nullable();
            $table->string('venue', 160);
            $table->date('show_date');
            $table->json('setlist');
            $table->string('photos_url', 2048)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['household_id', 'show_date']);
        });

        Schema::create('show_opening_acts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('show_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('band', 120);
            $table->json('setlist');
            $table->timestamps();

            $table->index(['show_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('show_opening_acts');
        Schema::dropIfExists('shows');
    }
};
