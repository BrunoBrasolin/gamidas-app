<?php

namespace Tests\Feature\Knowledge;

use App\Enums\KnowledgePageStatus;
use App\Models\KnowledgeAttachment;
use App\Models\KnowledgePage;
use App\Models\KnowledgeSubject;
use App\Support\KnowledgeContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\CreatesKnowledgeFiles;
use Tests\TestCase;

class KnowledgePageTest extends TestCase
{
    use CreatesHousehold, CreatesKnowledgeFiles, RefreshDatabase;

    private KnowledgeSubject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->createHousehold();
        $this->subject = KnowledgeSubject::factory()->for($this->leticia)->create(['title' => 'Biologia']);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    private function doc(array $blocks): array
    {
        return ['type' => 'doc', 'content' => $blocks];
    }

    /**
     * @return array<string, mixed>
     */
    private function paragraph(string $text, array $marks = []): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text, ...($marks === [] ? [] : ['marks' => $marks])]]];
    }

    private function upload(KnowledgePage $page, mixed $file = null): int
    {
        return (int) $this->actingAs($this->leticia)
            ->postJson(route('knowledge.attachments.store', $page), ['file' => $file ?? $this->png()])
            ->assertCreated()
            ->json('id');
    }

    public function test_pages_are_created_with_or_without_category(): void
    {
        $category = $this->subject->categories()->create(['title' => 'Genética', 'sort_order' => 1]);

        $this->actingAs($this->leticia)
            ->post(route('knowledge.pages.store', $this->subject), ['title' => ''])
            ->assertRedirect(route('knowledge.pages.edit', KnowledgePage::query()->sole()));

        $loose = KnowledgePage::query()->sole();
        $this->assertSame('Nova página', $loose->title);
        $this->assertNull($loose->category_id);
        $this->assertSame(KnowledgePageStatus::Unread, $loose->status);

        $this->actingAs($this->leticia)
            ->post(route('knowledge.pages.store', $this->subject), ['title' => 'DNA', 'category_id' => $category->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($category->id, KnowledgePage::query()->where('title', 'DNA')->sole()->category_id);
    }

    public function test_a_category_of_another_subject_is_rejected(): void
    {
        $other = KnowledgeSubject::factory()->for($this->leticia)->create();
        $category = $other->categories()->create(['title' => 'Outro', 'sort_order' => 1]);

        $this->actingAs($this->leticia)
            ->post(route('knowledge.pages.store', $this->subject), ['title' => 'DNA', 'category_id' => $category->id])
            ->assertSessionHasErrors('category_id');
    }

    public function test_autosave_stores_sanitized_content_and_its_text(): void
    {
        $page = KnowledgePage::factory()->for($this->subject, 'subject')->create();
        $theirs = KnowledgePage::factory()->for(KnowledgeSubject::factory()->for($this->bruno), 'subject')->create();
        $image = $this->upload($page);
        $foreign = KnowledgeAttachment::query()->create([
            'subject_id' => $theirs->subject_id, 'page_id' => $theirs->id, 'kind' => 'image', 'filename' => 'x.png',
            'mime_type' => 'image/png', 'size' => 1, 'disk' => 'local', 'path' => 'knowledge/x.png',
        ]);

        $content = $this->doc([
            ['type' => 'heading', 'attrs' => ['level' => 2, 'onclick' => 'alert(1)'], 'content' => [['type' => 'text', 'text' => 'Célula']]],
            $this->paragraph('Leia mais', [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']], ['type' => 'bold']]),
            $this->paragraph('Fonte', [['type' => 'link', 'attrs' => ['href' => 'https://example.test/x', 'target' => '_self']]]),
            ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'alert(1)']]],
            ['type' => 'knowledgeImage', 'attrs' => ['attachmentId' => $image, 'alt' => 'Mitocôndria']],
            ['type' => 'knowledgeImage', 'attrs' => ['attachmentId' => $foreign->id]],
            ['type' => 'knowledgeUpload', 'attrs' => ['uploadId' => 'abc']],
        ]);

        $this->actingAs($this->leticia)
            ->putJson(route('knowledge.pages.content', $page), ['title' => ' Organelas ', 'content' => $content, 'revision' => 0])
            ->assertOk()
            ->assertJson(['revision' => 1]);

        $page->refresh();
        $this->assertSame('Organelas', $page->title);
        $this->assertSame(1, $page->revision);
        $this->assertSame($this->doc([
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Célula']]],
            $this->paragraph('Leia mais', [['type' => 'bold']]),
            $this->paragraph('Fonte', [['type' => 'link', 'attrs' => ['href' => 'https://example.test/x']]]),
            ['type' => 'knowledgeImage', 'attrs' => ['attachmentId' => $image, 'alt' => 'Mitocôndria']],
        ]), $page->content);
        $this->assertSame("Célula\nLeia mais\nFonte\nMitocôndria", $page->content_text);
        $this->assertNull(KnowledgeAttachment::query()->findOrFail($image)->detached_at);
    }

    public function test_autosave_keeps_the_spaces_around_formatted_text(): void
    {
        $page = KnowledgePage::factory()->for($this->subject, 'subject')->create();
        $content = $this->doc([[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'A célula é a '],
                ['type' => 'text', 'text' => 'unidade básica', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' da vida. '],
            ],
        ]]);

        $this->actingAs($this->leticia)
            ->putJson(route('knowledge.pages.content', $page), ['title' => 'Célula', 'content' => $content, 'revision' => 0])
            ->assertOk();

        $this->assertSame($content, $page->refresh()->content);
        $this->assertSame('A célula é a unidade básica da vida.', $page->content_text);
    }

    public function test_a_stale_autosave_is_refused_unless_forced(): void
    {
        $page = KnowledgePage::factory()->for($this->subject, 'subject')->create(['revision' => 3]);
        $payload = ['title' => 'Antigo', 'content' => $this->doc([$this->paragraph('velho')]), 'revision' => 2];

        $this->actingAs($this->leticia)
            ->putJson(route('knowledge.pages.content', $page), $payload)
            ->assertStatus(409)
            ->assertJson(['revision' => 3]);

        $this->assertSame(3, $page->refresh()->revision);
        $this->assertNotSame('Antigo', $page->title);

        $this->actingAs($this->leticia)
            ->putJson(route('knowledge.pages.content', $page), [...$payload, 'force' => true])
            ->assertOk()
            ->assertJson(['revision' => 4]);

        $this->assertSame('Antigo', $page->refresh()->title);
    }

    public function test_invalid_content_is_rejected(): void
    {
        $page = KnowledgePage::factory()->for($this->subject, 'subject')->create();

        $this->actingAs($this->leticia)
            ->putJson(route('knowledge.pages.content', $page), ['title' => 'X', 'content' => ['type' => 'paragraph'], 'revision' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');

        config(['knowledge.max_content_kb' => 1]);

        $this->actingAs($this->leticia)
            ->putJson(route('knowledge.pages.content', $page), ['title' => 'X', 'content' => $this->doc([$this->paragraph(str_repeat('a', 2000))]), 'revision' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');
    }

    public function test_attachments_removed_from_the_text_are_detached_then_pruned(): void
    {
        $page = KnowledgePage::factory()->for($this->subject, 'subject')->create();
        $pdf = $this->upload($page, $this->pdf());
        $attachment = KnowledgeAttachment::query()->findOrFail($pdf);
        $this->assertNotNull($attachment->detached_at, 'New uploads wait for the text that places them.');

        $this->actingAs($this->leticia)->putJson(route('knowledge.pages.content', $page), [
            'title' => 'X', 'content' => $this->doc([['type' => 'knowledgeFile', 'attrs' => ['attachmentId' => $pdf]]]), 'revision' => 0,
        ])->assertOk();
        $this->assertNull($attachment->refresh()->detached_at);

        $this->actingAs($this->leticia)->putJson(route('knowledge.pages.content', $page), [
            'title' => 'X', 'content' => $this->doc([$this->paragraph('sem anexo')]), 'revision' => 1,
        ])->assertOk();
        $this->assertNotNull($attachment->refresh()->detached_at);

        $this->artisan('knowledge:prune-attachments')->assertSuccessful();
        Storage::disk('local')->assertExists($attachment->path);

        $this->travel(25)->hours();
        $this->artisan('knowledge:prune-attachments')->assertSuccessful();

        $this->assertNull(KnowledgeAttachment::query()->find($pdf));
        Storage::disk('local')->assertMissing($attachment->path);
    }

    public function test_prune_removes_files_without_attachment_but_keeps_placed_ones(): void
    {
        $page = KnowledgePage::factory()->for($this->subject, 'subject')->create();
        $image = $this->upload($page);
        $this->actingAs($this->leticia)->putJson(route('knowledge.pages.content', $page), [
            'title' => 'X', 'content' => $this->doc([['type' => 'knowledgeImage', 'attrs' => ['attachmentId' => $image]]]), 'revision' => 0,
        ])->assertOk();
        Storage::disk('local')->put('knowledge/'.$this->leticia->id.'/orfao.png', 'x');

        $this->travel(25)->hours();
        $this->artisan('knowledge:prune-attachments')->assertSuccessful();

        Storage::disk('local')->assertMissing('knowledge/'.$this->leticia->id.'/orfao.png');
        Storage::disk('local')->assertExists(KnowledgeAttachment::query()->findOrFail($image)->path);
    }

    public function test_status_bookmark_rename_and_archive(): void
    {
        $page = KnowledgePage::factory()->for($this->subject, 'subject')->create();

        $this->actingAs($this->leticia)->put(route('knowledge.pages.status', $page), ['status' => 'reviewed'])->assertSessionHasNoErrors();
        $this->assertSame(KnowledgePageStatus::Reviewed, $page->refresh()->status);

        $this->actingAs($this->leticia)->put(route('knowledge.pages.status', $page), ['status' => 'done'])->assertSessionHasErrors('status');

        $this->actingAs($this->leticia)->post(route('knowledge.pages.bookmark', $page));
        $this->assertNotNull($page->refresh()->bookmarked_at);
        $this->actingAs($this->leticia)->delete(route('knowledge.pages.unbookmark', $page));
        $this->assertNull($page->refresh()->bookmarked_at);

        $this->actingAs($this->leticia)->put(route('knowledge.pages.update', $page), ['title' => ' Mitose '])->assertSessionHasNoErrors();
        $this->assertSame('Mitose', $page->refresh()->title);

        $this->actingAs($this->leticia)->post(route('knowledge.pages.archive', $page));
        $this->assertNotNull($page->refresh()->archived_at);
        $this->actingAs($this->leticia)->delete(route('knowledge.pages.restore', $page));
        $this->assertNull($page->refresh()->archived_at);
    }

    public function test_duplicating_copies_the_text_and_the_files(): void
    {
        $page = KnowledgePage::factory()->for($this->subject, 'subject')->create(['title' => 'DNA', 'sort_order' => 1]);
        $after = KnowledgePage::factory()->for($this->subject, 'subject')->create(['sort_order' => 2]);
        $image = $this->upload($page);
        $this->actingAs($this->leticia)->putJson(route('knowledge.pages.content', $page), [
            'title' => 'DNA', 'content' => $this->doc([['type' => 'knowledgeImage', 'attrs' => ['attachmentId' => $image]]]), 'revision' => 0,
        ])->assertOk();

        $this->actingAs($this->leticia)->post(route('knowledge.pages.duplicate', $page))->assertRedirect();

        $copy = KnowledgePage::query()->where('title', 'DNA (cópia)')->sole();
        $this->assertSame(2, $copy->sort_order);
        $this->assertSame(3, $after->refresh()->sort_order);

        $copied = $copy->attachments()->sole();
        $this->assertNotSame($image, $copied->id);
        $this->assertSame([$copied->id], KnowledgeContent::attachmentIds($copy->content));
        Storage::disk('local')->assertExists($copied->path);
        Storage::disk('local')->assertExists(KnowledgeAttachment::query()->findOrFail($image)->path);
    }

    public function test_pages_move_between_categories_and_subjects_with_their_files(): void
    {
        $page = KnowledgePage::factory()->for($this->subject, 'subject')->create();
        $image = $this->upload($page);
        $other = KnowledgeSubject::factory()->for($this->leticia)->create();
        $category = $other->categories()->create(['title' => 'Cap. 1', 'sort_order' => 1]);
        $theirs = KnowledgeSubject::factory()->for($this->bruno)->create();

        $this->actingAs($this->leticia)
            ->put(route('knowledge.pages.move', $page), ['subject_id' => $theirs->id, 'category_id' => null])
            ->assertSessionHasErrors('subject_id');

        $this->actingAs($this->leticia)
            ->put(route('knowledge.pages.move', $page), ['subject_id' => $other->id, 'category_id' => $category->id])
            ->assertSessionHasNoErrors();

        $page->refresh();
        $this->assertSame($other->id, $page->subject_id);
        $this->assertSame($category->id, $page->category_id);
        $this->assertSame($other->id, KnowledgeAttachment::query()->findOrFail($image)->subject_id);
    }

    public function test_arranging_the_table_of_contents(): void
    {
        $one = $this->subject->categories()->create(['title' => 'Um', 'sort_order' => 1]);
        $two = $this->subject->categories()->create(['title' => 'Dois', 'sort_order' => 2]);
        $a = KnowledgePage::factory()->for($this->subject, 'subject')->create(['category_id' => $one->id, 'sort_order' => 1]);
        $b = KnowledgePage::factory()->for($this->subject, 'subject')->create(['category_id' => $one->id, 'sort_order' => 2]);
        $c = KnowledgePage::factory()->for($this->subject, 'subject')->create(['sort_order' => 1]);

        $this->actingAs($this->leticia)->put(route('knowledge.subjects.arrange', $this->subject), [
            'categories' => [$two->id, $one->id],
            'pages' => [
                ['id' => $b->id, 'category_id' => $two->id],
                ['id' => $c->id, 'category_id' => $one->id],
                ['id' => $a->id, 'category_id' => $one->id],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $two->refresh()->sort_order);
        $this->assertSame([$two->id, 1], [$b->refresh()->category_id, $b->sort_order]);
        $this->assertSame([$one->id, 1], [$c->refresh()->category_id, $c->sort_order]);
        $this->assertSame([$one->id, 2], [$a->refresh()->category_id, $a->sort_order]);
    }

    public function test_deleting_a_category_keeps_its_pages(): void
    {
        $category = $this->subject->categories()->create(['title' => 'Genética', 'sort_order' => 1]);
        KnowledgePage::factory()->for($this->subject, 'subject')->create(['sort_order' => 1]);
        $page = KnowledgePage::factory()->for($this->subject, 'subject')->create(['category_id' => $category->id, 'sort_order' => 1]);

        $this->actingAs($this->leticia)->delete(route('knowledge.categories.destroy', $category))->assertRedirect();

        $this->assertSame(0, $this->subject->categories()->count());
        $this->assertSame([null, 2], [$page->refresh()->category_id, $page->sort_order]);
    }

    public function test_deleting_a_page_removes_its_files(): void
    {
        $page = KnowledgePage::factory()->for($this->subject, 'subject')->create();
        $this->upload($page);
        $this->upload($page, $this->pdf());

        $this->actingAs($this->leticia)
            ->delete(route('knowledge.pages.destroy', $page))
            ->assertRedirect(route('knowledge.subjects.show', $this->subject));

        $this->assertSame(0, KnowledgeAttachment::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('knowledge'));
    }

    public function test_reader_shows_neighbors_in_table_of_contents_order_and_remembers_position(): void
    {
        $category = $this->subject->categories()->create(['title' => 'Cap. 1', 'sort_order' => 1]);
        $loose = KnowledgePage::factory()->for($this->subject, 'subject')->create(['title' => 'Solta', 'sort_order' => 1]);
        $first = KnowledgePage::factory()->for($this->subject, 'subject')->create(['title' => 'Primeira', 'category_id' => $category->id, 'sort_order' => 1]);
        $second = KnowledgePage::factory()->for($this->subject, 'subject')->create(['title' => 'Segunda', 'category_id' => $category->id, 'sort_order' => 2, 'reading_position' => 420]);
        KnowledgePage::factory()->for($this->subject, 'subject')->create(['category_id' => $category->id, 'sort_order' => 3, 'archived_at' => now()]);

        $this->actingAs($this->leticia)
            ->get(route('knowledge.pages.show', $second))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('knowledge/read', false)
                ->where('page.title', 'Segunda')
                ->where('page.reading_position', 420)
                ->where('previous.id', $first->id)
                ->where('next.id', $loose->id)
                ->has('toc', 3));

        $this->assertNotNull($second->refresh()->last_read_at);

        $this->actingAs($this->leticia)
            ->putJson(route('knowledge.pages.reading', $second), ['position' => 777])
            ->assertOk();
        $this->assertSame(777, $second->refresh()->reading_position);
    }

    public function test_pages_of_other_users_answer_404(): void
    {
        $theirs = KnowledgePage::factory()->for(KnowledgeSubject::factory()->for($this->bruno), 'subject')->create();
        $content = ['title' => 'X', 'content' => $this->doc([]), 'revision' => 0];

        $this->actingAs($this->leticia)->get(route('knowledge.pages.show', $theirs))->assertNotFound();
        $this->actingAs($this->leticia)->get(route('knowledge.pages.edit', $theirs))->assertNotFound();
        $this->actingAs($this->leticia)->putJson(route('knowledge.pages.content', $theirs), $content)->assertNotFound();
        $this->actingAs($this->leticia)->putJson(route('knowledge.pages.reading', $theirs), ['position' => 1])->assertNotFound();
        $this->actingAs($this->leticia)->put(route('knowledge.pages.status', $theirs), ['status' => 'reviewed'])->assertNotFound();
        $this->actingAs($this->leticia)->post(route('knowledge.pages.duplicate', $theirs))->assertNotFound();
        $this->actingAs($this->leticia)->delete(route('knowledge.pages.destroy', $theirs))->assertNotFound();
        $this->actingAs($this->leticia)->postJson(route('knowledge.attachments.store', $theirs), ['file' => $this->png()])->assertNotFound();

        $this->assertSame(1, KnowledgePage::query()->count());
        $this->assertSame(0, KnowledgeAttachment::query()->count());
    }
}
