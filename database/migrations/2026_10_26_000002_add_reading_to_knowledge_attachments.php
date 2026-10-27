<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the built-in reader was left in a PDF, presentation or document.
     */
    public function up(): void
    {
        Schema::table('knowledge_attachments', function (Blueprint $table) {
            $table->unsignedInteger('reading_page')->nullable()->after('sort_order');
            $table->unsignedInteger('page_count')->nullable()->after('reading_page');
        });
    }

    public function down(): void
    {
        // The table may have been rebuilt without them by an earlier
        // migration's down/up (see KnowledgeMigrationTest).
        if (! Schema::hasColumn('knowledge_attachments', 'reading_page')) {
            return;
        }

        Schema::table('knowledge_attachments', function (Blueprint $table) {
            $table->dropColumn(['reading_page', 'page_count']);
        });
    }
};
