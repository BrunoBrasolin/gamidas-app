<?php

namespace Tests\Feature\Shows;

use App\Enums\Tier;
use App\Models\Show;
use App\Models\ShowTierEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class ShowTierTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    private Show $maiden;

    private Show $ghost;

    private Show $today;

    private Show $upcoming;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-20 10:00:00');
        $this->createHousehold();
        $this->maiden = $this->concert('Iron Maiden', '2024-12-06');
        $this->ghost = $this->concert('Ghost', '2025-09-20');
        $this->today = $this->concert('Gojira', '2026-11-20');
        $this->upcoming = $this->concert('Metallica', '2026-11-21');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function concert(string $band, string $date, ?int $householdId = null): Show
    {
        return Show::factory()->create(['household_id' => $householdId ?? $this->household->id, 'band' => $band, 'show_date' => $date]);
    }

    private function place(User $user, Show $show, Tier $tier, int $position = 0): void
    {
        ShowTierEntry::query()->create(['user_id' => $user->id, 'show_id' => $show->id, 'tier' => $tier, 'position' => $position]);
    }

    /**
     * @return array<string, list<int>>
     */
    private function tierList(User $user): array
    {
        $tiers = array_fill_keys(Tier::values(), []);

        foreach (ShowTierEntry::query()->where('user_id', $user->id)->orderBy('position')->get() as $entry) {
            $tiers[$entry->tier->value][] = $entry->show_id;
        }

        return $tiers;
    }

    public function test_the_page_shows_the_users_tiers_and_the_shows_that_already_happened(): void
    {
        $this->place($this->bruno, $this->maiden, Tier::S, 1);
        $this->place($this->bruno, $this->ghost, Tier::S, 0);
        $this->place($this->leticia, $this->today, Tier::F);

        $this->actingAs($this->bruno)
            ->get(route('shows.tiers'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('shows/tiers', false)
                ->where('tiers.S', [$this->ghost->id, $this->maiden->id])
                ->where('tiers.F', [])
                ->has('shows', 3)
                ->where('shows.0.band', 'Gojira')
                ->where('shows.2.band', 'Iron Maiden')
                ->where('shareUrl', null));
    }

    public function test_saving_replaces_the_users_list_and_leaves_the_other_members(): void
    {
        $this->place($this->bruno, $this->today, Tier::D);
        $this->place($this->leticia, $this->maiden, Tier::F);

        $this->actingAs($this->bruno)
            ->put(route('shows.tiers.update'), ['tiers' => ['S' => [$this->maiden->id], 'B' => [$this->ghost->id]]])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['S' => [$this->maiden->id], 'A' => [], 'B' => [$this->ghost->id], 'C' => [], 'D' => [], 'F' => []],
            $this->tierList($this->bruno),
        );
        $this->assertSame([$this->maiden->id], $this->tierList($this->leticia)['F']);
    }

    public function test_upcoming_repeated_and_other_households_shows_are_refused(): void
    {
        $outsider = $this->createOutsider();
        $theirs = $this->concert('Slipknot', '2025-01-01', $outsider->householdId());
        $this->actingAs($this->bruno);

        foreach ([
            ['S' => [$this->upcoming->id]],
            ['S' => [$this->maiden->id], 'A' => [$this->maiden->id]],
            ['S' => [$theirs->id]],
        ] as $tiers) {
            $this->put(route('shows.tiers.update'), ['tiers' => $tiers])->assertSessionHasErrors('tiers');
        }

        $this->assertDatabaseCount('show_tier_entries', 0);
    }

    public function test_a_show_already_ranked_stays_when_its_date_moves_ahead(): void
    {
        $this->place($this->bruno, $this->maiden, Tier::A);
        $this->maiden->update(['show_date' => '2027-01-01']);

        $this->actingAs($this->bruno)
            ->put(route('shows.tiers.update'), ['tiers' => ['S' => [$this->maiden->id]]])
            ->assertSessionHasNoErrors();

        $this->assertSame([$this->maiden->id], $this->tierList($this->bruno)['S']);
    }

    public function test_the_show_page_places_one_show_at_the_end_of_a_tier(): void
    {
        $this->actingAs($this->bruno);

        $this->put(route('shows.tier', $this->maiden), ['tier' => 'A'])->assertSessionHasNoErrors();
        $this->put(route('shows.tier', $this->ghost), ['tier' => 'A'])->assertSessionHasNoErrors();
        // The same tier keeps the place.
        $this->put(route('shows.tier', $this->maiden), ['tier' => 'A'])->assertSessionHasNoErrors();

        $this->assertSame([$this->maiden->id, $this->ghost->id], $this->tierList($this->bruno)['A']);

        $this->put(route('shows.tier', $this->ghost), ['tier' => null])->assertSessionHasNoErrors();
        $this->assertSame([$this->maiden->id], $this->tierList($this->bruno)['A']);

        $this->put(route('shows.tier', $this->upcoming), ['tier' => 'S'])->assertSessionHasErrors('tier');
        $this->put(route('shows.tier', $this->maiden), ['tier' => 'X'])->assertSessionHasErrors('tier');

        $this->get(route('shows.show', $this->maiden))
            ->assertInertia(fn (Assert $page) => $page->where('tier', 'A')->where('canRank', true));
        $this->get(route('shows.show', $this->upcoming))
            ->assertInertia(fn (Assert $page) => $page->where('tier', null)->where('canRank', false));
        $this->get(route('shows.index'))
            ->assertInertia(fn (Assert $page) => $page->where("tiers.{$this->maiden->id}", 'A'));
    }

    public function test_another_households_show_cannot_be_ranked(): void
    {
        $this->actingAs($this->createOutsider())
            ->put(route('shows.tier', $this->maiden), ['tier' => 'S'])
            ->assertNotFound();
    }

    public function test_deleting_a_show_takes_it_off_the_tier_lists(): void
    {
        $this->place($this->bruno, $this->maiden, Tier::S);

        $this->maiden->delete();

        $this->assertDatabaseCount('show_tier_entries', 0);
    }
}
