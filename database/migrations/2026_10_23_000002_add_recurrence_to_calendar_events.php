<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->string('recurrence_frequency', 10)->nullable()->after('icon');
            $table->unsignedSmallInteger('recurrence_interval')->default(1)->after('recurrence_frequency');
            $table->string('recurrence_weekdays', 20)->nullable()->after('recurrence_interval');
            $table->date('recurrence_until')->nullable()->after('recurrence_weekdays');
        });

        Schema::create('calendar_event_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained()->cascadeOnDelete();
            $table->date('original_date');
            $table->boolean('cancelled')->default(false);
            $table->string('title', 120)->nullable();
            $table->date('event_date')->nullable();
            $table->time('event_time')->nullable();
            $table->text('description')->nullable();
            $table->string('color', 20)->nullable();
            $table->string('icon', 30)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['calendar_event_id', 'original_date'], 'calendar_event_exceptions_occurrence_unique');
            $table->index('event_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_event_exceptions');

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropColumn(['recurrence_frequency', 'recurrence_interval', 'recurrence_weekdays', 'recurrence_until']);
        });
    }
};
