<?php

namespace Tests\Feature\Shows;

use App\Http\Requests\Shows\ShowRequest;
use App\Models\Show;
use App\Models\ShowOpeningAct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class ShowTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createHousehold();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'band' => ' Avenged Sevenfold ',
            'tour' => 'LIFE IS BUT A DREAM…',
            'venue' => 'Allianz Parque',
            'show_date' => '2026-01-31',
            'setlist' => "Nightcall\n\n  Game Over  \r\nMattel\n",
            'photos_url' => 'https://photos.app.goo.gl/abc123',
            'opening_acts' => [
                ['band' => 'A Day To Remember', 'setlist' => "The Downfall of Us All\nAll I Want"],
                ['band' => 'Mr. Bungle', 'setlist' => ''],
            ],
            ...$overrides,
        ];
    }

    public function test_shows_are_created_for_the_household(): void
    {
        $other = $this->createOutsider();

        $this->actingAs($this->bruno)
            ->post(route('shows.store'), [...$this->payload(), 'household_id' => $other->householdId()])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('shows.show', Show::query()->sole()));

        $show = Show::query()->sole();
        $this->assertSame($this->household->id, $show->household_id);
        $this->assertSame($this->bruno->id, $show->created_by_user_id);
        $this->assertSame('Avenged Sevenfold', $show->band);
        $this->assertSame('2026-01-31', $show->show_date->toDateString());
        $this->assertSame(['Nightcall', 'Game Over', 'Mattel'], $show->setlist);
        $this->assertSame('https://photos.app.goo.gl/abc123', $show->photos_url);

        $acts = $show->openingActs;
        $this->assertSame(['A Day To Remember', 'Mr. Bungle'], $acts->pluck('band')->all());
        $this->assertSame(['The Downfall of Us All', 'All I Want'], $acts[0]->setlist);
        $this->assertSame([], $acts[1]->setlist);
    }

    public function test_tour_photos_and_opening_acts_are_optional(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('shows.store'), $this->payload(['tour' => '', 'photos_url' => '', 'opening_acts' => []]))
            ->assertSessionHasNoErrors();

        $show = Show::query()->sole();
        $this->assertNull($show->tour);
        $this->assertNull($show->photos_url);
        $this->assertSame(0, $show->openingActs()->count());
    }

    public function test_required_fields_and_link_are_validated(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('shows.store'), $this->payload([
                'band' => '',
                'venue' => ' ',
                'show_date' => '2026-02-30',
                'photos_url' => 'javascript:alert(1)',
                'opening_acts' => [['band' => '', 'setlist' => 'Song']],
            ]))
            ->assertSessionHasErrors(['band', 'venue', 'show_date', 'photos_url', 'opening_acts.0.band']);

        $this->actingAs($this->bruno)
            ->post(route('shows.store'), $this->payload(['photos_url' => 'http://photos.app.goo.gl/abc']))
            ->assertSessionHasErrors('photos_url');

        $this->assertSame(0, Show::query()->count());
    }

    public function test_setlist_numbering_is_dropped(): void
    {
        $this->assertSame(
            ['Nightcall', 'Game Over', '2nd Sucks', '1979'],
            ShowRequest::songs("1. Nightcall\n2) Game Over\n3 - 2nd Sucks\n1979"),
        );
    }

    public function test_updating_replaces_the_opening_acts(): void
    {
        $show = Show::factory()->for($this->household)->create();
        $show->openingActs()->create(['position' => 0, 'band' => 'Antiga', 'setlist' => ['X']]);

        // Any member of the household can edit.
        $this->actingAs($this->leticia)
            ->put(route('shows.update', $show), $this->payload([
                'opening_acts' => [['band' => 'Poppy', 'setlist' => "BLOODMONEY\nV.A.N"]],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('shows.show', $show));

        $this->assertSame('Avenged Sevenfold', $show->refresh()->band);
        $this->assertSame(['Poppy'], $show->openingActs()->pluck('band')->all());
        $this->assertSame(1, ShowOpeningAct::query()->count());
    }

    public function test_deleting_removes_the_opening_acts(): void
    {
        $show = Show::factory()->for($this->household)->create();
        $show->openingActs()->create(['position' => 0, 'band' => 'Volbeat', 'setlist' => []]);

        $this->actingAs($this->leticia)
            ->delete(route('shows.destroy', $show))
            ->assertRedirect(route('shows.index'));

        $this->assertSame(0, Show::query()->count());
        $this->assertSame(0, ShowOpeningAct::query()->count());
    }

    public function test_shows_of_other_households_answer_404(): void
    {
        $outsider = $this->createOutsider();
        $theirs = Show::factory()->create(['household_id' => $outsider->householdId()]);

        $this->actingAs($this->bruno)->get(route('shows.show', $theirs))->assertNotFound();
        $this->actingAs($this->bruno)->get(route('shows.edit', $theirs))->assertNotFound();
        $this->actingAs($this->bruno)->put(route('shows.update', $theirs), $this->payload())->assertNotFound();
        $this->actingAs($this->bruno)->delete(route('shows.destroy', $theirs))->assertNotFound();

        $this->assertSame(1, Show::query()->count());
    }

    public function test_index_lists_the_household_shows_newest_first_with_stats(): void
    {
        $older = Show::factory()->for($this->household)->create(['band' => 'Iron Maiden', 'venue' => 'Allianz Parque', 'show_date' => '2024-12-06', 'setlist' => ['Doctor Doctor', 'The Trooper']]);
        $older->openingActs()->create(['position' => 0, 'band' => 'Volbeat', 'setlist' => ['Lola Montez']]);
        Show::factory()->for($this->household)->create(['band' => 'iron maiden', 'created_by_user_id' => $this->bruno->id, 'venue' => 'Morumbis', 'show_date' => '2026-01-31', 'setlist' => ['Aces High']]);
        Show::factory()->create(['band' => 'Outra', 'household_id' => $this->createOutsider()->householdId()]);

        // Both members see the same shows.
        $this->actingAs($this->leticia)
            ->get(route('shows.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('shows/index', false)
                ->has('shows', 2)
                ->where('shows.0.date', '2026-01-31')
                ->where('shows.0.created_by', 'Bruno')
                ->where('shows.1.created_by', null)
                ->where('shows.1.opening_acts.0.band', 'Volbeat')
                ->where('stats', ['shows' => 2, 'bands' => 2, 'venues' => 2, 'songs' => 4]));
    }
}
