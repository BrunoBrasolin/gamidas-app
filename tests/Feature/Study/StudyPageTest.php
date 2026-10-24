<?php

namespace Tests\Feature\Study;

use App\Enums\StudyPageStatus;
use App\Models\StudyAttachment;
use App\Models\StudyPage;
use App\Models\StudySubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\CreatesStudyFiles;
use Tests\TestCase;

class StudyPageTest extends TestCase
{
    use CreatesHousehold, CreatesStudyFiles, RefreshDatabase;

    private StudySubject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->createHousehold();
        $this->subject = StudySubject::factory()->for($this->leticia)->create(['title' => 'Biologia']);
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

    private function upload(StudyPage $page, mixed $file = null): int
    {
        return (int) $this->actingAs($this->leticia)
            ->postJson(route('study.attachments.store', $page), ['file' => $file ?? $this->png()])
            ->assertCreated()
            ->json('id');
    }

    public function test_pages_are_created_with_or_without_chapter(): void
    {
        $chapter = $this->subject->chapters()->create(['title' => 'Genética', 'sort_order' => 1]);

        $this->actingAs($this->leticia)
            ->post(route('study.pages.store', $this->subject), ['title' => ''])
            ->assertRedirect(route('study.pages.edit', StudyPage::query()->sole()));

        $loose = StudyPage::query()->sole();
        $this->assertSame('Nova página', $loose->title);
        $this->assertNull($loose->chapter_id);
        $this->assertSame(StudyPageStatus::Unread, $loose->status);

        $this->actingAs($this->leticia)
            ->post(route('study.pages.store', $this->subject), ['title' => 'DNA', 'chapter_id' => $chapter->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($chapter->id, StudyPage::query()->where('title', 'DNA')->sole()->chapter_id);
    }

    public function test_a_chapter_of_another_subject_is_rejected(): void
    {
        $other = StudySubject::factory()->for($this->leticia)->create();
        $chapter = $other->chapters()->create(['title' => 'Outro', 'sort_order' => 1]);

        $this->actingAs($this->leticia)
            ->post(route('study.pages.store', $this->subject), ['title' => 'DNA', 'chapter_id' => $chapter->id])
            ->assertSessionHasErrors('chapter_id');
    }

    public function test_autosave_stores_sanitized_content_and_its_text(): void
    {
        $page = StudyPage::factory()->for($this->subject, 'subject')->create();
        $theirs = StudyPage::factory()->for(StudySubject::factory()->for($this->bruno), 'subject')->create();
        $image = $this->upload($page);
        $foreign = StudyAttachment::query()->create([
            'subject_id' => $theirs->subject_id, 'page_id' => $theirs->id, 'kind' => 'image', 'filename' => 'x.png',
            'mime_type' => 'image/png', 'size' => 1, 'disk' => 'local', 'path' => 'study/x.png',
        ]);

        $content = $this->doc([
            ['type' => 'heading', 'attrs' => ['level' => 2, 'onclick' => 'alert(1)'], 'content' => [['type' => 'text', 'text' => 'Célula']]],
            $this->paragraph('Leia mais', [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']], ['type' => 'bold']]),
            $this->paragraph('Fonte', [['type' => 'link', 'attrs' => ['href' => 'https://example.test/x', 'target' => '_self']]]),
            ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'alert(1)']]],
            ['type' => 'studyImage', 'attrs' => ['attachmentId' => $image, 'alt' => 'Mitocôndria']],
            ['type' => 'studyImage', 'attrs' => ['attachmentId' => $foreign->id]],
            ['type' => 'studyUpload', 'attrs' => ['uploadId' => 'abc']],
        ]);

        $this->actingAs($this->leticia)
            ->putJson(route('study.pages.content', $page), ['title' => ' Organelas ', 'content' => $content, 'revision' => 0])
            ->assertOk()
            ->assertJson(['revision' => 1]);

        $page->refresh();
        $this->assertSame('Organelas', $page->title);
        $this->assertSame(1, $page->revision);
        $this->assertSame($this->doc([
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Célula']]],
            $this->paragraph('Leia mais', [['type' => 'bold']]),
            $this->paragraph('Fonte', [['type' => 'link', 'attrs' => ['href' => 'https://example.test/x']]]),
            ['type' => 'studyImage', 'attrs' => ['attachmentId' => $image, 'alt' => 'Mitocôndria']],
        ]), $page->content);
        $this->assertSame("Célula\nLeia mais\nFonte\nMitocôndria", $page->content_text);
        $this->assertNull(StudyAttachment::query()->findOrFail($image)->detached_at);
    }

    public function test_a_stale_autosave_is_refused_unless_forced(): void
    {
        $page = StudyPage::factory()->for($this->subject, 'subject')->create(['revision' => 3]);
        $payload = ['title' => 'Antigo', 'content' => $this->doc([$this->paragraph('velho')]), 'revision' => 2];

        $this->actingAs($this->leticia)
            ->putJson(route('study.pages.content', $page), $payload)
            ->assertStatus(409)
            ->assertJson(['revision' => 3]);

        $this->assertSame(3, $page->refresh()->revision);
        $this->assertNotSame('Antigo', $page->title);

        $this->actingAs($this->leticia)
            ->putJson(route('study.pages.content', $page), [...$payload, 'force' => true])
            ->assertOk()
            ->assertJson(['revision' => 4]);

        $this->assertSame('Antigo', $page->refresh()->title);
    }

    public function test_invalid_content_is_rejected(): void
    {
        $page = StudyPage::factory()->for($this->subject, 'subject')->create();

        $this->actingAs($this->leticia)
            ->putJson(route('study.pages.content', $page), ['title' => 'X', 'content' => ['type' => 'paragraph'], 'revision' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');

        config(['study.max_content_kb' => 1]);

        $this->actingAs($this->leticia)
            ->putJson(route('study.pages.content', $page), ['title' => 'X', 'content' => $this->doc([$this->paragraph(str_repeat('a', 2000))]), 'revision' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');
    }

    public function test_attachments_removed_from_the_text_are_detached_then_pruned(): void
    {
        $page = StudyPage::factory()->for($this->subject, 'subject')->create();
        $pdf = $this->upload($page, $this->pdf());
        $attachment = StudyAttachment::query()->findOrFail($pdf);
        $this->assertNotNull($attachment->detached_at, 'New uploads wait for the text that places them.');

        $this->actingAs($this->leticia)->putJson(route('study.pages.content', $page), [
            'title' => 'X', 'content' => $this->doc([['type' => 'studyFile', 'attrs' => ['attachmentId' => $pdf]]]), 'revision' => 0,
        ])->assertOk();
        $this->assertNull($attachment->refresh()->detached_at);

        $this->actingAs($this->leticia)->putJson(route('study.pages.content', $page), [
            'title' => 'X', 'content' => $this->doc([$this->paragraph('sem anexo')]), 'revision' => 1,
        ])->assertOk();
        $this->assertNotNull($attachment->refresh()->detached_at);

        $this->artisan('study:prune-attachments')->assertSuccessful();
        Storage::disk('local')->assertExists($attachment->path);

        $this->travel(25)->hours();
        $this->artisan('study:prune-attachments')->assertSuccessful();

        $this->assertNull(StudyAttachment::query()->find($pdf));
        Storage::disk('local')->assertMissing($attachment->path);
    }

    public function test_prune_removes_files_without_attachment_but_keeps_placed_ones(): void
    {
        $page = StudyPage::factory()->for($this->subject, 'subject')->create();
        $image = $this->upload($page);
        $this->actingAs($this->leticia)->putJson(route('study.pages.content', $page), [
            'title' => 'X', 'content' => $this->doc([['type' => 'studyImage', 'attrs' => ['attachmentId' => $image]]]), 'revision' => 0,
        ])->assertOk();
        Storage::disk('local')->put('study/'.$this->leticia->id.'/orfao.png', 'x');

        $this->travel(25)->hours();
        $this->artisan('study:prune-attachments')->assertSuccessful();

        Storage::disk('local')->assertMissing('study/'.$this->leticia->id.'/orfao.png');
        Storage::disk('local')->assertExists(StudyAttachment::query()->findOrFail($image)->path);
    }

    public function test_status_bookmark_rename_and_archive(): void
    {
        $page = StudyPage::factory()->for($this->subject, 'subject')->create();

        $this->actingAs($this->leticia)->put(route('study.pages.status', $page), ['status' => 'reviewed'])->assertSessionHasNoErrors();
        $this->assertSame(StudyPageStatus::Reviewed, $page->refresh()->status);

        $this->actingAs($this->leticia)->put(route('study.pages.status', $page), ['status' => 'done'])->assertSessionHasErrors('status');

        $this->actingAs($this->leticia)->post(route('study.pages.bookmark', $page));
        $this->assertNotNull($page->refresh()->bookmarked_at);
        $this->actingAs($this->leticia)->delete(route('study.pages.unbookmark', $page));
        $this->assertNull($page->refresh()->bookmarked_at);

        $this->actingAs($this->leticia)->put(route('study.pages.update', $page), ['title' => ' Mitose '])->assertSessionHasNoErrors();
        $this->assertSame('Mitose', $page->refresh()->title);

        $this->actingAs($this->leticia)->post(route('study.pages.archive', $page));
        $this->assertNotNull($page->refresh()->archived_at);
        $this->actingAs($this->leticia)->delete(route('study.pages.restore', $page));
        $this->assertNull($page->refresh()->archived_at);
    }

    public function test_duplicating_copies_the_text_and_the_files(): void
    {
        $page = StudyPage::factory()->for($this->subject, 'subject')->create(['title' => 'DNA', 'sort_order' => 1]);
        $after = StudyPage::factory()->for($this->subject, 'subject')->create(['sort_order' => 2]);
        $image = $this->upload($page);
        $this->actingAs($this->leticia)->putJson(route('study.pages.content', $page), [
            'title' => 'DNA', 'content' => $this->doc([['type' => 'studyImage', 'attrs' => ['attachmentId' => $image]]]), 'revision' => 0,
        ])->assertOk();

        $this->actingAs($this->leticia)->post(route('study.pages.duplicate', $page))->assertRedirect();

        $copy = StudyPage::query()->where('title', 'DNA (cópia)')->sole();
        $this->assertSame(2, $copy->sort_order);
        $this->assertSame(3, $after->refresh()->sort_order);

        $copied = $copy->attachments()->sole();
        $this->assertNotSame($image, $copied->id);
        $this->assertSame([$copied->id], \App\Support\StudyContent::attachmentIds($copy->content));
        Storage::disk('local')->assertExists($copied->path);
        Storage::disk('local')->assertExists(StudyAttachment::query()->findOrFail($image)->path);
    }

    public function test_pages_move_between_chapters_and_subjects_with_their_files(): void
    {
        $page = StudyPage::factory()->for($this->subject, 'subject')->create();
        $image = $this->upload($page);
        $other = StudySubject::factory()->for($this->leticia)->create();
        $chapter = $other->chapters()->create(['title' => 'Cap. 1', 'sort_order' => 1]);
        $theirs = StudySubject::factory()->for($this->bruno)->create();

        $this->actingAs($this->leticia)
            ->put(route('study.pages.move', $page), ['subject_id' => $theirs->id, 'chapter_id' => null])
            ->assertSessionHasErrors('subject_id');

        $this->actingAs($this->leticia)
            ->put(route('study.pages.move', $page), ['subject_id' => $other->id, 'chapter_id' => $chapter->id])
            ->assertSessionHasNoErrors();

        $page->refresh();
        $this->assertSame($other->id, $page->subject_id);
        $this->assertSame($chapter->id, $page->chapter_id);
        $this->assertSame($other->id, StudyAttachment::query()->findOrFail($image)->subject_id);
    }

    public function test_arranging_the_table_of_contents(): void
    {
        $one = $this->subject->chapters()->create(['title' => 'Um', 'sort_order' => 1]);
        $two = $this->subject->chapters()->create(['title' => 'Dois', 'sort_order' => 2]);
        $a = StudyPage::factory()->for($this->subject, 'subject')->create(['chapter_id' => $one->id, 'sort_order' => 1]);
        $b = StudyPage::factory()->for($this->subject, 'subject')->create(['chapter_id' => $one->id, 'sort_order' => 2]);
        $c = StudyPage::factory()->for($this->subject, 'subject')->create(['sort_order' => 1]);

        $this->actingAs($this->leticia)->put(route('study.subjects.arrange', $this->subject), [
            'chapters' => [$two->id, $one->id],
            'pages' => [
                ['id' => $b->id, 'chapter_id' => $two->id],
                ['id' => $c->id, 'chapter_id' => $one->id],
                ['id' => $a->id, 'chapter_id' => $one->id],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $two->refresh()->sort_order);
        $this->assertSame([$two->id, 1], [$b->refresh()->chapter_id, $b->sort_order]);
        $this->assertSame([$one->id, 1], [$c->refresh()->chapter_id, $c->sort_order]);
        $this->assertSame([$one->id, 2], [$a->refresh()->chapter_id, $a->sort_order]);
    }

    public function test_deleting_a_chapter_keeps_its_pages(): void
    {
        $chapter = $this->subject->chapters()->create(['title' => 'Genética', 'sort_order' => 1]);
        StudyPage::factory()->for($this->subject, 'subject')->create(['sort_order' => 1]);
        $page = StudyPage::factory()->for($this->subject, 'subject')->create(['chapter_id' => $chapter->id, 'sort_order' => 1]);

        $this->actingAs($this->leticia)->delete(route('study.chapters.destroy', $chapter))->assertRedirect();

        $this->assertSame(0, $this->subject->chapters()->count());
        $this->assertSame([null, 2], [$page->refresh()->chapter_id, $page->sort_order]);
    }

    public function test_deleting_a_page_removes_its_files(): void
    {
        $page = StudyPage::factory()->for($this->subject, 'subject')->create();
        $this->upload($page);
        $this->upload($page, $this->pdf());

        $this->actingAs($this->leticia)
            ->delete(route('study.pages.destroy', $page))
            ->assertRedirect(route('study.subjects.show', $this->subject));

        $this->assertSame(0, StudyAttachment::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('study'));
    }

    public function test_reader_shows_neighbors_in_table_of_contents_order_and_remembers_position(): void
    {
        $chapter = $this->subject->chapters()->create(['title' => 'Cap. 1', 'sort_order' => 1]);
        $loose = StudyPage::factory()->for($this->subject, 'subject')->create(['title' => 'Solta', 'sort_order' => 1]);
        $first = StudyPage::factory()->for($this->subject, 'subject')->create(['title' => 'Primeira', 'chapter_id' => $chapter->id, 'sort_order' => 1]);
        $second = StudyPage::factory()->for($this->subject, 'subject')->create(['title' => 'Segunda', 'chapter_id' => $chapter->id, 'sort_order' => 2, 'reading_position' => 420]);
        StudyPage::factory()->for($this->subject, 'subject')->create(['chapter_id' => $chapter->id, 'sort_order' => 3, 'archived_at' => now()]);

        $this->actingAs($this->leticia)
            ->get(route('study.pages.show', $second))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('study/read', false)
                ->where('page.title', 'Segunda')
                ->where('page.reading_position', 420)
                ->where('previous.id', $first->id)
                ->where('next.id', $loose->id)
                ->has('toc', 3));

        $this->assertNotNull($second->refresh()->last_read_at);

        $this->actingAs($this->leticia)
            ->putJson(route('study.pages.reading', $second), ['position' => 777])
            ->assertOk();
        $this->assertSame(777, $second->refresh()->reading_position);
    }

    public function test_pages_of_other_users_answer_404(): void
    {
        $theirs = StudyPage::factory()->for(StudySubject::factory()->for($this->bruno), 'subject')->create();
        $content = ['title' => 'X', 'content' => $this->doc([]), 'revision' => 0];

        $this->actingAs($this->leticia)->get(route('study.pages.show', $theirs))->assertNotFound();
        $this->actingAs($this->leticia)->get(route('study.pages.edit', $theirs))->assertNotFound();
        $this->actingAs($this->leticia)->putJson(route('study.pages.content', $theirs), $content)->assertNotFound();
        $this->actingAs($this->leticia)->putJson(route('study.pages.reading', $theirs), ['position' => 1])->assertNotFound();
        $this->actingAs($this->leticia)->put(route('study.pages.status', $theirs), ['status' => 'reviewed'])->assertNotFound();
        $this->actingAs($this->leticia)->post(route('study.pages.duplicate', $theirs))->assertNotFound();
        $this->actingAs($this->leticia)->delete(route('study.pages.destroy', $theirs))->assertNotFound();
        $this->actingAs($this->leticia)->postJson(route('study.attachments.store', $theirs), ['file' => $this->png()])->assertNotFound();

        $this->assertSame(1, StudyPage::query()->count());
        $this->assertSame(0, StudyAttachment::query()->count());
    }
}
