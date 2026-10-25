<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The household's checklist of things to take to a show, configured once,
     * and which of those items were already packed for each show.
     */
    public function up(): void
    {
        Schema::create('show_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('label', 120);
            $table->timestamps();

            $table->index(['household_id', 'position']);
        });

        Schema::create('show_checklist_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('show_id')->constrained()->cascadeOnDelete();
            $table->foreignId('show_checklist_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['show_id', 'show_checklist_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('show_checklist_checks');
        Schema::dropIfExists('show_checklist_items');
    }
};
