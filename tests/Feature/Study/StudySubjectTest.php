<?php

namespace Tests\Feature\Study;

use App\Enums\StudyPageStatus;
use App\Models\StudyAttachment;
use App\Models\StudyPage;
use App\Models\StudySubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\CreatesStudyFiles;
use Tests\TestCase;

class StudySubjectTest extends TestCase
{
    use CreatesHousehold, CreatesStudyFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->createHousehold();
    }

    public function test_subjects_are_created_for_the_signed_in_user(): void
    {
        $this->actingAs($this->leticia)
            ->post(route('study.subjects.store'), [
                'title' => ' Biologia ',
                'description' => '',
                'accent_color' => 'sage',
                'user_id' => $this->bruno->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('study.subjects.show', StudySubject::query()->sole()));

        $subject = StudySubject::query()->sole();
        $this->assertSame($this->leticia->id, $subject->user_id);
        $this->assertSame('Biologia', $subject->title);
        $this->assertNull($subject->description);
        $this->assertSame(1, $subject->sort_order);
    }

    public function test_title_and_color_are_validated(): void
    {
        $this->actingAs($this->leticia)
            ->post(route('study.subjects.store'), ['title' => ' ', 'accent_color' => 'neon'])
            ->assertSessionHasErrors(['title', 'accent_color']);

        $this->assertSame(0, StudySubject::query()->count());
    }

    public function test_cover_image_is_stored_privately_and_replaced(): void
    {
        $this->actingAs($this->leticia)->post(route('study.subjects.store'), [
            'title' => 'Química',
            'accent_color' => 'teal',
            'cover' => $this->png('capa.png', 300, 400),
        ])->assertSessionHasNoErrors();

        $subject = StudySubject::query()->sole();
        $cover = $subject->cover;
        $this->assertNotNull($cover);
        $this->assertSame('image/png', $cover->mime_type);
        $this->assertSame([300, 400], [$cover->width, $cover->height]);
        $this->assertNull($cover->page_id);
        Storage::disk('local')->assertExists($cover->path);
        $this->assertStringStartsWith('study/'.$this->leticia->id.'/', $cover->path);

        $this->actingAs($this->leticia)->put(route('study.subjects.update', $subject), [
            'title' => 'Química',
            'accent_color' => 'teal',
            'cover' => $this->png('nova.png', 200, 300),
        ])->assertSessionHasNoErrors();

        Storage::disk('local')->assertMissing($cover->path);
        $this->assertSame(1, StudyAttachment::query()->count());

        $this->actingAs($this->leticia)->put(route('study.subjects.update', $subject), [
            'title' => 'Química',
            'accent_color' => 'teal',
            'remove_cover' => true,
        ])->assertSessionHasNoErrors();

        $this->assertNull($subject->refresh()->cover_attachment_id);
        $this->assertSame(0, StudyAttachment::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('study'));
    }

    public function test_a_cover_that_is_not_an_image_is_rejected(): void
    {
        $this->actingAs($this->leticia)->post(route('study.subjects.store'), [
            'title' => 'Química',
            'accent_color' => 'teal',
            'cover' => UploadedFile::fake()->createWithContent('capa.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ])->assertSessionHasErrors('cover');

        $this->assertSame(0, StudySubject::query()->count());
    }

    public function test_subjects_can_be_archived_pinned_and_reordered(): void
    {
        $first = StudySubject::factory()->for($this->leticia)->create(['sort_order' => 1]);
        $second = StudySubject::factory()->for($this->leticia)->create(['sort_order' => 2]);
        $theirs = StudySubject::factory()->for($this->bruno)->create(['sort_order' => 1]);

        $this->actingAs($this->leticia)->post(route('study.subjects.pin', $first))->assertRedirect();
        $this->assertTrue($first->refresh()->is_pinned);

        $this->actingAs($this->leticia)->post(route('study.subjects.archive', $first));
        $this->assertNotNull($first->refresh()->archived_at);
        $this->assertFalse($first->is_pinned);

        $this->actingAs($this->leticia)->delete(route('study.subjects.restore', $first));
        $this->assertNull($first->refresh()->archived_at);

        $this->actingAs($this->leticia)
            ->put(route('study.subjects.order'), ['ids' => [$second->id, $theirs->id, $first->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $second->refresh()->sort_order);
        $this->assertSame(3, $first->refresh()->sort_order);
        $this->assertSame(1, $theirs->refresh()->sort_order);
    }

    public function test_deleting_a_subject_removes_pages_and_files(): void
    {
        $subject = StudySubject::factory()->for($this->leticia)->create();
        $page = StudyPage::factory()->for($subject, 'subject')->create();

        $this->actingAs($this->leticia)
            ->postJson(route('study.attachments.store', $page), ['file' => $this->png()])
            ->assertCreated();

        $this->assertCount(1, Storage::disk('local')->allFiles('study'));

        $this->actingAs($this->leticia)
            ->delete(route('study.subjects.destroy', $subject))
            ->assertRedirect(route('study.index'));

        $this->assertSame(0, StudySubject::query()->count());
        $this->assertSame(0, StudyPage::query()->count());
        $this->assertSame(0, StudyAttachment::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('study'));
    }

    public function test_subjects_of_other_users_answer_404(): void
    {
        $theirs = StudySubject::factory()->for($this->bruno)->create();
        $payload = ['title' => 'X', 'accent_color' => 'sage'];

        $this->actingAs($this->leticia)->get(route('study.subjects.show', $theirs))->assertNotFound();
        $this->actingAs($this->leticia)->put(route('study.subjects.update', $theirs), $payload)->assertNotFound();
        $this->actingAs($this->leticia)->delete(route('study.subjects.destroy', $theirs))->assertNotFound();
        $this->actingAs($this->leticia)->post(route('study.subjects.archive', $theirs))->assertNotFound();
        $this->actingAs($this->leticia)->post(route('study.subjects.pin', $theirs))->assertNotFound();
        $this->actingAs($this->leticia)->post(route('study.pages.store', $theirs), ['title' => 'X'])->assertNotFound();
        $this->actingAs($this->leticia)->post(route('study.chapters.store', $theirs), ['title' => 'X'])->assertNotFound();

        $this->assertSame(1, StudySubject::query()->count());
        $this->assertSame(0, StudyPage::query()->count());
    }

    public function test_library_lists_only_own_subjects_with_counts_and_recent_pages(): void
    {
        $subject = StudySubject::factory()->for($this->leticia)->create(['title' => 'Biologia']);
        StudyPage::factory()->for($subject, 'subject')->create(['status' => StudyPageStatus::Reviewed]);
        StudyPage::factory()->for($subject, 'subject')->create(['title' => 'DNA', 'last_read_at' => now(), 'bookmarked_at' => now()]);
        StudyPage::factory()->for($subject, 'subject')->create(['archived_at' => now()]);
        StudySubject::factory()->for($this->bruno)->create(['title' => 'Dele']);

        $this->actingAs($this->leticia)
            ->get(route('study.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('study/index', false)
                ->has('subjects', 1)
                ->where('subjects.0.title', 'Biologia')
                ->where('subjects.0.counts.pages', 2)
                ->where('subjects.0.counts.reviewed', 1)
                ->where('subjects.0.continue_page.title', 'DNA')
                ->has('recent_pages', 1)
                ->where('bookmarks.0.title', 'DNA'));
    }

    public function test_search_finds_subjects_chapters_and_page_text(): void
    {
        $subject = StudySubject::factory()->for($this->leticia)->create(['title' => 'Biologia', 'description' => 'Estudo das células']);
        $subject->chapters()->create(['title' => 'Célula animal', 'sort_order' => 1]);
        StudyPage::factory()->for($subject, 'subject')->create(['title' => 'Organelas', 'content_text' => 'A mitocôndria produz energia para a célula.']);
        $theirs = StudySubject::factory()->for($this->bruno)->create(['title' => 'Mitocôndria dele']);
        StudyPage::factory()->for($theirs, 'subject')->create(['content_text' => 'mitocôndria']);

        $this->actingAs($this->leticia)
            ->get(route('study.search', ['q' => 'mitocôndria']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('study/search', false)
                ->has('results.subjects', 0)
                ->has('results.pages', 1)
                ->where('results.pages.0.title', 'Organelas')
                ->where('results.pages.0.excerpt', 'A mitocôndria produz energia para a célula.'));

        $this->actingAs($this->leticia)
            ->get(route('study.search', ['q' => 'Célula']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('results.subjects', 1)
                ->has('results.chapters', 1));

        $this->actingAs($this->leticia)
            ->get(route('study.search', ['q' => '%']))
            ->assertInertia(fn (Assert $page) => $page->has('results.pages', 0));
    }
}
