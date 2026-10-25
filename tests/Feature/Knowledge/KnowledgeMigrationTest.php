<?php

namespace Tests\Feature\Knowledge;

use App\Models\KnowledgeAttachment;
use App\Models\KnowledgeCategory;
use App\Models\KnowledgePage;
use App\Models\KnowledgeSubject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

/**
 * Runs outside a transaction (DatabaseMigrations): on SQLite, adding a foreign
 * key rebuilds the table, and foreign keys can only be switched off for that
 * outside a transaction.
 */
class KnowledgeMigrationTest extends TestCase
{
    use CreatesHousehold, DatabaseMigrations;

    public function test_the_study_library_is_moved_to_the_knowledge_base(): void
    {
        Storage::fake('local');
        $this->createHousehold();

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_10_25_000001_move_study_to_knowledge_base.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('knowledge_subjects'));

        $now = now()->toDateTimeString();
        DB::table('study_subjects')->insert(['id' => 7, 'user_id' => $this->leticia->id, 'title' => 'Biologia', 'accent_color' => 'teal', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('study_chapters')->insert(['id' => 3, 'subject_id' => 7, 'title' => 'Célula', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('study_pages')->insert([
            'id' => 11, 'subject_id' => 7, 'chapter_id' => 3, 'title' => 'Mitocôndria', 'revision' => 2, 'sort_order' => 1, 'status' => 'reviewed', 'created_at' => $now, 'updated_at' => $now,
            'content' => json_encode(['type' => 'doc', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'studyImage']]],
                ['type' => 'studyImage', 'attrs' => ['attachmentId' => 5, 'alt' => 'Organela']],
            ]]),
        ]);
        DB::table('study_attachments')->insert(['id' => 5, 'subject_id' => 7, 'page_id' => 11, 'kind' => 'image', 'filename' => 'a.png', 'mime_type' => 'image/png', 'size' => 3, 'disk' => 'local', 'path' => "study/{$this->leticia->id}/abc.png", 'created_at' => $now, 'updated_at' => $now]);
        DB::table('study_subjects')->where('id', 7)->update(['cover_attachment_id' => 5]);
        Storage::disk('local')->put("study/{$this->leticia->id}/abc.png", 'png');

        $migration->up();

        $this->assertFalse(Schema::hasTable('study_subjects'));
        $this->assertSame(5, KnowledgeSubject::query()->findOrFail(7)->cover_attachment_id);

        $category = KnowledgeCategory::query()->findOrFail(3);
        $this->assertSame(['Célula', null, 7], [$category->title, $category->parent_id, $category->subject_id]);

        $page = KnowledgePage::query()->findOrFail(11);
        $this->assertSame([3, 2, 'reviewed'], [$page->category_id, $page->revision, $page->status->value]);
        $this->assertSame('knowledgeImage', $page->content['content'][1]['type'] ?? null);
        $this->assertSame('studyImage', $page->content['content'][0]['content'][0]['text'] ?? null);

        $attachment = KnowledgeAttachment::query()->findOrFail(5);
        $this->assertSame("knowledge/{$this->leticia->id}/abc.png", $attachment->path);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame([], Storage::disk('local')->allFiles('study'));
    }
}
