<?php

namespace Tests\Feature\Time;

use App\Models\DailyNote;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class DailyNoteTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-10-21 10:00:00');
        $this->createHousehold();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function save(string $content, int $revision, string $date = '2026-10-21'): TestResponse
    {
        return $this->actingAs($this->bruno)->putJson(route('time.notes.update', $date), [
            'content' => $content,
            'base_revision' => $revision,
        ]);
    }

    public function test_notes_are_saved_with_their_exact_markdown(): void
    {
        $markdown = "  # Hoje\n\n- [x] Feito  \n- [ ] Pendente\n\n```php\necho 1;\n```\n";

        $this->save($markdown, 0)
            ->assertOk()
            ->assertJson(['date' => '2026-10-21', 'content' => $markdown, 'revision' => 1]);

        $this->save($markdown.'Mais', 1)->assertOk()->assertJson(['revision' => 2]);

        $this->getJson(route('time.notes.show', '2026-10-21'))
            ->assertOk()
            ->assertJson(['content' => $markdown.'Mais', 'revision' => 2]);

        $this->get(route('time.days.show', '2026-10-21'))
            ->assertInertia(fn (Assert $page) => $page->where('note.content', $markdown.'Mais'));
    }

    public function test_potentially_malicious_markdown_is_stored_as_inert_text(): void
    {
        $payload = '<script>alert(1)</script> [x](javascript:alert(2)) <img src=x onerror=alert(3)>';

        $this->save($payload, 0)->assertOk();

        // Stored verbatim; the page receives it as JSON data, never as HTML.
        $this->assertSame($payload, DailyNote::query()->sole()->content);
        $this->get(route('time.days.show', '2026-10-21'))
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_one_note_per_user_and_day(): void
    {
        $this->save('Primeira', 0);
        $this->save('Segunda', 1);

        $this->assertDatabaseCount('daily_notes', 1);

        $this->expectException(UniqueConstraintViolationException::class);
        DailyNote::query()->create(['user_id' => $this->bruno->id, 'note_date' => '2026-10-21', 'content' => 'Duplicada']);
    }

    public function test_a_stale_revision_is_refused_instead_of_overwriting(): void
    {
        $this->save('Aba A', 0);

        $this->save('Aba B, desatualizada', 0)
            ->assertStatus(409)
            ->assertJson(['content' => 'Aba A', 'revision' => 1]);

        $this->assertSame('Aba A', DailyNote::query()->sole()->content);

        // After choosing to keep their version, the editor saves on the current revision.
        $this->save('Aba B, mantida', 1)->assertOk()->assertJson(['revision' => 2]);
    }

    public function test_blank_content_removes_the_note(): void
    {
        $this->save('Algo', 0);
        $this->save("  \n ", 1)->assertOk()->assertJson(['content' => '', 'revision' => 0]);

        $this->assertDatabaseCount('daily_notes', 0);
    }

    public function test_large_notes_are_accepted_up_to_the_limit(): void
    {
        $this->save(str_repeat('a', DailyNote::MAX_LENGTH), 0)->assertOk();
        $this->save(str_repeat('a', DailyNote::MAX_LENGTH + 1), 1)->assertUnprocessable();
    }

    public function test_notes_are_private_to_their_owner(): void
    {
        $this->save('Segredo do Bruno', 0);

        $this->actingAs($this->leticia)
            ->getJson(route('time.notes.show', '2026-10-21'))
            ->assertOk()
            ->assertJson(['content' => '', 'revision' => 0]);

        $this->get(route('time.notes.index', ['q' => 'Segredo']))
            ->assertInertia(fn (Assert $page) => $page->has('notes.data', 0));

        // Letícia's own note for the same day is a separate record.
        $this->putJson(route('time.notes.update', '2026-10-21'), ['content' => 'Minha', 'base_revision' => 0])->assertOk();
        $this->assertDatabaseCount('daily_notes', 2);
        $this->assertSame('Segredo do Bruno', DailyNote::query()->where('user_id', $this->bruno->id)->value('content'));
    }

    public function test_notes_can_be_searched_by_text(): void
    {
        $this->save('Estudei Rust 100%', 0, '2026-10-20');
        $this->save('Li um livro', 0, '2026-10-21');

        $this->actingAs($this->bruno)
            ->get(route('time.notes.index', ['q' => 'rust']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('notes.data', 1)
                ->where('notes.data.0.date', '2026-10-20'));

        // Wildcards are searched literally.
        $this->get(route('time.notes.index', ['q' => '%']))
            ->assertInertia(fn (Assert $page) => $page->has('notes.data', 1));

        $this->get(route('time.notes.index'))
            ->assertInertia(fn (Assert $page) => $page->has('notes.data', 2)->where('notes.data.0.date', '2026-10-21'));
    }

    public function test_invalid_note_dates_are_not_found(): void
    {
        $this->actingAs($this->bruno)->putJson('/time/notes/2026-13-01', ['content' => 'x', 'base_revision' => 0])->assertNotFound();
    }
}
