<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /** Editor node types stored in the page documents, old => new. */
    private const NODES = ['studyImage' => 'knowledgeImage', 'studyFile' => 'knowledgeFile'];

    /**
     * Run the migrations.
     *
     * The study library becomes the knowledge base: the study_* tables are
     * copied into new knowledge_* tables (same ids, so links keep working) and
     * dropped. Chapters become categories, which can be nested through
     * parent_id; existing chapters become top-level categories. The editor
     * node types inside the page documents and the folder of the files on the
     * private disk are renamed too.
     */
    public function up(): void
    {
        Schema::create('knowledge_subjects', function (Blueprint $table) {
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

        Schema::create('knowledge_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('knowledge_subjects')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('knowledge_categories')->cascadeOnDelete();
            $table->string('title', 160);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['subject_id', 'parent_id', 'sort_order']);
        });

        Schema::create('knowledge_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('knowledge_subjects')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('knowledge_categories')->nullOnDelete();
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

            $table->index(['subject_id', 'category_id', 'sort_order']);
            $table->index(['subject_id', 'last_read_at']);
        });

        Schema::create('knowledge_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('knowledge_subjects')->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('knowledge_pages')->cascadeOnDelete();
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

        if (Schema::hasTable('study_subjects')) {
            $this->copy('study', 'knowledge', self::NODES);
            $this->moveFiles('study', 'knowledge');
            $this->dropTables('study');
        }

        Schema::table('knowledge_subjects', function (Blueprint $table) {
            $table->foreign('cover_attachment_id')->references('id')->on('knowledge_attachments')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations. Nested categories come back as flat chapters.
     */
    public function down(): void
    {
        (require __DIR__.'/2026_10_24_000002_create_study_tables.php')->up();

        // The recreated study_subjects already points its cover to study_attachments.
        Schema::withoutForeignKeyConstraints(fn () => $this->copy('knowledge', 'study', array_flip(self::NODES)));
        $this->moveFiles('knowledge', 'study');
        $this->dropTables('knowledge');
    }

    /**
     * Copy every row from one set of tables to the other, keeping the ids.
     *
     * @param  array<string, string>  $nodes
     */
    private function copy(string $from, string $to, array $nodes): void
    {
        [$fromGroups, $fromGroup] = $this->groups($from);
        [$toGroups, $toGroup] = $this->groups($to);

        $common = ['id', 'created_at', 'updated_at'];

        DB::table("{$to}_subjects")->insertUsing(
            [...$common, 'user_id', 'title', 'description', 'accent_color', 'cover_attachment_id', 'is_pinned', 'sort_order', 'last_opened_at', 'archived_at'],
            DB::table("{$from}_subjects")->select([...$common, 'user_id', 'title', 'description', 'accent_color', 'cover_attachment_id', 'is_pinned', 'sort_order', 'last_opened_at', 'archived_at']),
        );

        DB::table($toGroups)->insertUsing(
            [...$common, 'subject_id', 'title', 'sort_order'],
            DB::table($fromGroups)->select([...$common, 'subject_id', 'title', 'sort_order']),
        );

        $pageColumns = [...$common, 'subject_id', 'title', 'content', 'content_text', 'revision', 'sort_order', 'status', 'reading_position', 'last_read_at', 'bookmarked_at', 'archived_at'];

        DB::table("{$from}_pages")->orderBy('id')->chunk(100, function ($pages) use ($to, $pageColumns, $fromGroup, $toGroup, $nodes) {
            DB::table("{$to}_pages")->insert($pages->map(function (object $page) use ($pageColumns, $fromGroup, $toGroup, $nodes) {
                $row = [];

                foreach ($pageColumns as $column) {
                    $row[$column] = $page->{$column};
                }

                $row[$toGroup] = $page->{$fromGroup};
                $row['content'] = $this->renameNodes($page->content, $nodes);

                return $row;
            })->all());
        });

        $attachmentColumns = [...$common, 'subject_id', 'page_id', 'kind', 'filename', 'mime_type', 'size', 'disk', 'path', 'width', 'height', 'sort_order', 'detached_at'];

        DB::table("{$from}_attachments")->orderBy('id')->chunk(500, function ($attachments) use ($from, $to, $attachmentColumns) {
            DB::table("{$to}_attachments")->insert($attachments->map(function (object $attachment) use ($from, $to, $attachmentColumns) {
                $row = [];

                foreach ($attachmentColumns as $column) {
                    $row[$column] = $attachment->{$column};
                }

                if (str_starts_with((string) $row['path'], "{$from}/")) {
                    $row['path'] = $to.substr((string) $row['path'], strlen($from));
                }

                return $row;
            })->all());
        });
    }

    /**
     * Rename the attachment node types inside an editor document (JSON).
     *
     * @param  array<string, string>  $nodes
     */
    private function renameNodes(mixed $content, array $nodes): mixed
    {
        if (! is_string($content)) {
            return $content;
        }

        $document = json_decode($content, true);

        if (! is_array($document)) {
            return $content;
        }

        $walk = function (array $node) use (&$walk, $nodes): array {
            if (isset($node['type']) && is_string($node['type']) && isset($nodes[$node['type']])) {
                $node['type'] = $nodes[$node['type']];
            }

            if (isset($node['content']) && is_array($node['content'])) {
                $node['content'] = array_map(fn ($child) => is_array($child) ? $walk($child) : $child, $node['content']);
            }

            return $node;
        };

        return json_encode($walk($document), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Rename the files' folder on every disk the attachments live on.
     */
    private function moveFiles(string $from, string $to): void
    {
        foreach (DB::table("{$to}_attachments")->distinct()->pluck('disk') as $name) {
            $disk = Storage::disk((string) $name);

            foreach ($disk->allFiles($from) as $path) {
                $disk->move($path, $to.substr($path, strlen($from)));
            }

            $disk->deleteDirectory($from);
        }
    }

    /**
     * The table grouping a subject's pages and the page column pointing to it.
     *
     * @return array{string, string}
     */
    private function groups(string $prefix): array
    {
        return $prefix === 'study' ? ['study_chapters', 'chapter_id'] : ['knowledge_categories', 'category_id'];
    }

    private function dropTables(string $prefix): void
    {
        Schema::table("{$prefix}_subjects", function (Blueprint $table) {
            $table->dropForeign(['cover_attachment_id']);
        });

        Schema::dropIfExists("{$prefix}_attachments");
        Schema::dropIfExists("{$prefix}_pages");
        Schema::dropIfExists($this->groups($prefix)[0]);
        Schema::dropIfExists("{$prefix}_subjects");
    }
};
