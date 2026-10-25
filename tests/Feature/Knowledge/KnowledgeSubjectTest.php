<?php

namespace Tests\Feature\Knowledge;

use App\Enums\KnowledgePageStatus;
use App\Models\KnowledgeAttachment;
use App\Models\KnowledgePage;
use App\Models\KnowledgeSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\CreatesKnowledgeFiles;
use Tests\TestCase;

class KnowledgeSubjectTest extends TestCase
{
    use CreatesHousehold, CreatesKnowledgeFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->createHousehold();
    }

    public function test_subjects_are_created_for_the_signed_in_user(): void
    {
        $this->actingAs($this->leticia)
            ->post(route('knowledge.subjects.store'), [
                'title' => ' Biologia ',
                'description' => '',
                'accent_color' => 'sage',
                'user_id' => $this->bruno->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('knowledge.subjects.show', KnowledgeSubject::query()->sole()));

        $subject = KnowledgeSubject::query()->sole();
        $this->assertSame($this->leticia->id, $subject->user_id);
        $this->assertSame('Biologia', $subject->title);
        $this->assertNull($subject->description);
        $this->assertSame(1, $subject->sort_order);
    }

    public function test_title_and_color_are_validated(): void
    {
        $this->actingAs($this->leticia)
            ->post(route('knowledge.subjects.store'), ['title' => ' ', 'accent_color' => 'neon'])
            ->assertSessionHasErrors(['title', 'accent_color']);

        $this->assertSame(0, KnowledgeSubject::query()->count());
    }

    public function test_cover_image_is_stored_privately_and_replaced(): void
    {
        $this->actingAs($this->leticia)->post(route('knowledge.subjects.store'), [
            'title' => 'Química',
            'accent_color' => 'teal',
            'cover' => $this->png('capa.png', 300, 400),
        ])->assertSessionHasNoErrors();

        $subject = KnowledgeSubject::query()->sole();
        $cover = $subject->cover;
        $this->assertNotNull($cover);
        $this->assertSame('image/png', $cover->mime_type);
        $this->assertSame([300, 400], [$cover->width, $cover->height]);
        $this->assertNull($cover->page_id);
        Storage::disk('local')->assertExists($cover->path);
        $this->assertStringStartsWith('knowledge/'.$this->leticia->id.'/', $cover->path);

        $this->actingAs($this->leticia)->put(route('knowledge.subjects.update', $subject), [
            'title' => 'Química',
            'accent_color' => 'teal',
            'cover' => $this->png('nova.png', 200, 300),
        ])->assertSessionHasNoErrors();

        Storage::disk('local')->assertMissing($cover->path);
        $this->assertSame(1, KnowledgeAttachment::query()->count());

        $this->actingAs($this->leticia)->put(route('knowledge.subjects.update', $subject), [
            'title' => 'Química',
            'accent_color' => 'teal',
            'remove_cover' => true,
        ])->assertSessionHasNoErrors();

        $this->assertNull($subject->refresh()->cover_attachment_id);
        $this->assertSame(0, KnowledgeAttachment::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('knowledge'));
    }

    public function test_a_cover_that_is_not_an_image_is_rejected(): void
    {
        $this->actingAs($this->leticia)->post(route('knowledge.subjects.store'), [
            'title' => 'Química',
            'accent_color' => 'teal',
            'cover' => UploadedFile::fake()->createWithContent('capa.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ])->assertSessionHasErrors('cover');

        $this->assertSame(0, KnowledgeSubject::query()->count());
    }

    public function test_subjects_can_be_archived_pinned_and_reordered(): void
    {
        $first = KnowledgeSubject::factory()->for($this->leticia)->create(['sort_order' => 1]);
        $second = KnowledgeSubject::factory()->for($this->leticia)->create(['sort_order' => 2]);
        $theirs = KnowledgeSubject::factory()->for($this->bruno)->create(['sort_order' => 1]);

        $this->actingAs($this->leticia)->post(route('knowledge.subjects.pin', $first))->assertRedirect();
        $this->assertTrue($first->refresh()->is_pinned);

        $this->actingAs($this->leticia)->post(route('knowledge.subjects.archive', $first));
        $this->assertNotNull($first->refresh()->archived_at);
        $this->assertFalse($first->is_pinned);

        $this->actingAs($this->leticia)->delete(route('knowledge.subjects.restore', $first));
        $this->assertNull($first->refresh()->archived_at);

        $this->actingAs($this->leticia)
            ->put(route('knowledge.subjects.order'), ['ids' => [$second->id, $theirs->id, $first->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $second->refresh()->sort_order);
        $this->assertSame(3, $first->refresh()->sort_order);
        $this->assertSame(1, $theirs->refresh()->sort_order);
    }

    public function test_deleting_a_subject_removes_pages_and_files(): void
    {
        $subject = KnowledgeSubject::factory()->for($this->leticia)->create();
        $page = KnowledgePage::factory()->for($subject, 'subject')->create();

        $this->actingAs($this->leticia)
            ->postJson(route('knowledge.attachments.store', $page), ['file' => $this->png()])
            ->assertCreated();

        $this->assertCount(1, Storage::disk('local')->allFiles('knowledge'));

        $this->actingAs($this->leticia)
            ->delete(route('knowledge.subjects.destroy', $subject))
            ->assertRedirect(route('knowledge.index'));

        $this->assertSame(0, KnowledgeSubject::query()->count());
        $this->assertSame(0, KnowledgePage::query()->count());
        $this->assertSame(0, KnowledgeAttachment::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('knowledge'));
    }

    public function test_subjects_of_other_users_answer_404(): void
    {
        $theirs = KnowledgeSubject::factory()->for($this->bruno)->create();
        $payload = ['title' => 'X', 'accent_color' => 'sage'];

        $this->actingAs($this->leticia)->get(route('knowledge.subjects.show', $theirs))->assertNotFound();
        $this->actingAs($this->leticia)->put(route('knowledge.subjects.update', $theirs), $payload)->assertNotFound();
        $this->actingAs($this->leticia)->delete(route('knowledge.subjects.destroy', $theirs))->assertNotFound();
        $this->actingAs($this->leticia)->post(route('knowledge.subjects.archive', $theirs))->assertNotFound();
        $this->actingAs($this->leticia)->post(route('knowledge.subjects.pin', $theirs))->assertNotFound();
        $this->actingAs($this->leticia)->post(route('knowledge.pages.store', $theirs), ['title' => 'X'])->assertNotFound();
        $this->actingAs($this->leticia)->post(route('knowledge.categories.store', $theirs), ['title' => 'X'])->assertNotFound();

        $this->assertSame(1, KnowledgeSubject::query()->count());
        $this->assertSame(0, KnowledgePage::query()->count());
    }

    public function test_library_lists_only_own_subjects_with_counts_and_recent_pages(): void
    {
        $subject = KnowledgeSubject::factory()->for($this->leticia)->create(['title' => 'Biologia']);
        KnowledgePage::factory()->for($subject, 'subject')->create(['status' => KnowledgePageStatus::Reviewed]);
        KnowledgePage::factory()->for($subject, 'subject')->create(['title' => 'DNA', 'last_read_at' => now(), 'bookmarked_at' => now()]);
        KnowledgePage::factory()->for($subject, 'subject')->create(['archived_at' => now()]);
        KnowledgeSubject::factory()->for($this->bruno)->create(['title' => 'Dele']);

        $this->actingAs($this->leticia)
            ->get(route('knowledge.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('knowledge/index', false)
                ->has('subjects', 1)
                ->where('subjects.0.title', 'Biologia')
                ->where('subjects.0.counts.pages', 2)
                ->where('subjects.0.counts.reviewed', 1)
                ->where('subjects.0.continue_page.title', 'DNA')
                ->has('recent_pages', 1)
                ->where('bookmarks.0.title', 'DNA'));
    }

    public function test_search_finds_subjects_categories_and_page_text(): void
    {
        $subject = KnowledgeSubject::factory()->for($this->leticia)->create(['title' => 'Biologia', 'description' => 'Estudo das células']);
        $subject->categories()->create(['title' => 'Célula animal', 'sort_order' => 1]);
        KnowledgePage::factory()->for($subject, 'subject')->create(['title' => 'Organelas', 'content_text' => 'A mitocôndria produz energia para a célula.']);
        $theirs = KnowledgeSubject::factory()->for($this->bruno)->create(['title' => 'Mitocôndria dele']);
        KnowledgePage::factory()->for($theirs, 'subject')->create(['content_text' => 'mitocôndria']);

        $this->actingAs($this->leticia)
            ->get(route('knowledge.search', ['q' => 'mitocôndria']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('knowledge/search', false)
                ->has('results.subjects', 0)
                ->has('results.pages', 1)
                ->where('results.pages.0.title', 'Organelas')
                ->where('results.pages.0.excerpt', 'A mitocôndria produz energia para a célula.'));

        $this->actingAs($this->leticia)
            ->get(route('knowledge.search', ['q' => 'Célula']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('results.subjects', 1)
                ->has('results.categories', 1));

        $this->actingAs($this->leticia)
            ->get(route('knowledge.search', ['q' => '%']))
            ->assertInertia(fn (Assert $page) => $page->has('results.pages', 0));
    }
}
