<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The study library: subjects (books) owned by a user, optional chapters,
     * pages with the editor document (JSON) plus its plain text for search,
     * and image/PDF attachments stored on a private disk. Files on disk are
     * deleted by the services; the cascades only keep the metadata consistent.
     */
    public function up(): void
    {
        Schema::create('study_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('accent_color', 20);
            $table->unsignedBigInteger('cover_attachment_id')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'archived_at']);
        });

        Schema::create('study_chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('study_subjects')->cascadeOnDelete();
            $table->string('title', 160);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['subject_id', 'sort_order']);
        });

        Schema::create('study_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('study_subjects')->cascadeOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained('study_chapters')->nullOnDelete();
            $table->string('title', 200);
            $table->json('content')->nullable();
            $table->longText('content_text')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('unread');
            $table->unsignedSmallInteger('reading_position')->default(0);
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('bookmarked_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['subject_id', 'chapter_id', 'sort_order']);
            $table->index(['subject_id', 'last_read_at']);
        });

        Schema::create('study_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('study_subjects')->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('study_pages')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('filename', 200);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->string('disk', 20);
            $table->string('path', 255);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('detached_at')->nullable();
            $table->timestamps();

            $table->index(['page_id', 'sort_order']);
            $table->index('detached_at');
        });

        Schema::table('study_subjects', function (Blueprint $table) {
            $table->foreign('cover_attachment_id')->references('id')->on('study_attachments')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('study_subjects', function (Blueprint $table) {
            $table->dropForeign(['cover_attachment_id']);
        });

        Schema::dropIfExists('study_attachments');
        Schema::dropIfExists('study_pages');
        Schema::dropIfExists('study_chapters');
        Schema::dropIfExists('study_subjects');
    }
};
