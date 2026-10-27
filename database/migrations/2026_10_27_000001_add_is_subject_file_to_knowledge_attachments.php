<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Files attached to a subject itself (lesson slides, handouts), listed in
     * the subject's "Anexos" instead of being placed in a page.
     */
    public function up(): void
    {
        Schema::table('knowledge_attachments', function (Blueprint $table) {
            $table->boolean('is_subject_file')->default(false)->after('page_id');
        });
    }

    public function down(): void
    {
        // The table may have been rebuilt without it by an earlier
        // migration's down/up (see KnowledgeMigrationTest).
        if (! Schema::hasColumn('knowledge_attachments', 'is_subject_file')) {
            return;
        }

        Schema::table('knowledge_attachments', function (Blueprint $table) {
            $table->dropColumn('is_subject_file');
        });
    }
};
