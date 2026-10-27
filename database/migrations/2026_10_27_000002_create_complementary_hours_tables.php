<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * College "horas complementares": each user sets how many hours they need
     * and registers activities with a certificate and an optional report.
     */
    public function up(): void
    {
        Schema::create('complementary_hours_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('required_minutes');
            $table->timestamps();
        });

        Schema::create('complementary_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->date('completed_on')->nullable();
            $table->unsignedInteger('minutes');
            $table->string('certificate_disk', 40);
            $table->string('certificate_path');
            $table->string('certificate_filename', 200);
            $table->string('certificate_mime', 100);
            $table->unsignedBigInteger('certificate_size');
            $table->mediumText('report')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'completed_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('complementary_activities');
        Schema::dropIfExists('complementary_hours_goals');
    }
};
