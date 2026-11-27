<?php

namespace Tests\Feature\Games;

use App\Enums\BacklogStatus;
use App\Enums\Tier;
use App\Models\SteamAccount;
use App\Models\SteamBacklogEntry;
use App\Models\SteamGame;
use App\Models\SteamTierEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\FakesSteam;
use Tests\TestCase;

class GameListsTest extends TestCase
{
    use CreatesHousehold, FakesSteam, RefreshDatabase;

    private SteamAccount $account;

    private SteamGame $portal;

    private SteamGame $hades;

    private SteamGame $celeste;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-20 10:00:00');
        $this->createHousehold();
        $this->configureSteam();
        $this->fakeSteam();
        $this->account = $this->connectSteam($this->bruno, self::BRUNO_STEAM_ID);
        $this->portal = $this->game(620, 'Portal 2', 600);
        $this->hades = $this->game(1145360, 'Hades', 4_200);
        $this->celeste = $this->game(504230, 'Celeste', 0);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function game(int $appId, string $name, int $minutes): SteamGame
    {
        return SteamGame::factory()->for($this->account, 'account')->create(['app_id' => $appId, 'name' => $name, 'playtime_minutes' => $minutes]);
    }

    /**
     * @return array<string, list<int>>
     */
    private function tierList(): array
    {
        $tiers = array_fill_keys(Tier::values(), []);

        foreach (SteamTierEntry::query()->where('user_id', $this->bruno->id)->orderBy('position')->get() as $entry) {
            $tiers[$entry->tier->value][] = $entry->app_id;
        }

        return $tiers;
    }

    public function test_the_tier_list_page_shows_the_tiers_and_the_library(): void
    {
        SteamTierEntry::query()->create(['user_id' => $this->bruno->id, 'app_id' => 620, 'name' => 'Portal 2', 'tier' => Tier::S, 'position' => 1]);
        SteamTierEntry::query()->create(['user_id' => $this->bruno->id, 'app_id' => 1145360, 'name' => 'Hades', 'tier' => Tier::S, 'position' => 0]);

        $this->actingAs($this->bruno)
            ->get(route('games.tiers'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('games/tiers', false)
                ->where('tiers.S', [
                    ['app_id' => 1145360, 'name' => 'Hades', 'game_id' => $this->hades->id, 'playtime_minutes' => 4_200, 'last_played_at' => null],
                    ['app_id' => 620, 'name' => 'Portal 2', 'game_id' => $this->portal->id, 'playtime_minutes' => 600, 'last_played_at' => null],
                ])
                ->where('tiers.F', [])
                ->has('library', 3)
                ->where('library.0.name', 'Hades'));

        Http::assertNothingSent();
    }

    public function test_saving_the_tier_list_replaces_it_in_the_given_order(): void
    {
        SteamTierEntry::query()->create(['user_id' => $this->bruno->id, 'app_id' => 504230, 'name' => 'Celeste', 'tier' => Tier::C, 'position' => 0]);

        $this->actingAs($this->bruno)
            ->from(route('games.tiers'))
            ->put(route('games.tiers.update'), ['tiers' => ['S' => [1145360, 620], 'A' => [], 'B' => [504230]]])
            ->assertRedirect(route('games.tiers'))
            ->assertSessionHasNoErrors();

        $this->assertSame(['S' => [1145360, 620], 'A' => [], 'B' => [504230], 'C' => [], 'D' => [], 'F' => []], $this->tierList());
        $this->assertSame('Hades', SteamTierEntry::query()->where('app_id', 1145360)->value('name'));

        // Taking everything off.
        $this->actingAs($this->bruno)->put(route('games.tiers.update'), ['tiers' => []])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('steam_tier_entries', 0);
    }

    public function test_a_tier_list_with_unknown_or_repeated_games_is_refused(): void
    {
        $this->actingAs($this->bruno)
            ->put(route('games.tiers.update'), ['tiers' => ['S' => [620], 'A' => [620]]])
            ->assertSessionHasErrors('tiers');

        $this->actingAs($this->bruno)
            ->put(route('games.tiers.update'), ['tiers' => ['S' => [620, 999_999]]])
            ->assertSessionHasErrors('tiers');

        $this->actingAs($this->bruno)
            ->put(route('games.tiers.update'), ['tiers' => ['Z' => [620]]])
            ->assertSessionHasErrors('tiers');

        // Letícia's library is not Bruno's.
        $this->actingAs($this->leticia)
            ->put(route('games.tiers.update'), ['tiers' => ['S' => [620]]])
            ->assertSessionHasErrors('tiers');

        $this->assertDatabaseCount('steam_tier_entries', 0);
    }

    public function test_each_user_has_their_own_tier_list(): void
    {
        SteamTierEntry::query()->create(['user_id' => $this->leticia->id, 'app_id' => 620, 'name' => 'Portal 2', 'tier' => Tier::F, 'position' => 0]);

        $this->actingAs($this->bruno)->put(route('games.tiers.update'), ['tiers' => ['S' => [620]]])->assertSessionHasNoErrors();

        $this->assertSame('F', SteamTierEntry::query()->where('user_id', $this->leticia->id)->sole()->tier->value);
        $this->assertSame('S', SteamTierEntry::query()->where('user_id', $this->bruno->id)->sole()->tier->value);
    }

    public function test_a_game_page_sets_the_games_tier(): void
    {
        $this->actingAs($this->bruno)->put(route('games.tier', $this->portal), ['tier' => 'A'])->assertSessionHasNoErrors();
        $this->actingAs($this->bruno)->put(route('games.tier', $this->hades), ['tier' => 'A'])->assertSessionHasNoErrors();
        // Same tier again: keeps its place.
        $this->actingAs($this->bruno)->put(route('games.tier', $this->portal), ['tier' => 'A'])->assertSessionHasNoErrors();

        $this->assertSame([620, 1145360], $this->tierList()['A']);

        $this->actingAs($this->bruno)->put(route('games.tier', $this->portal), ['tier' => 'S']);
        $this->actingAs($this->bruno)->put(route('games.tier', $this->hades), ['tier' => null]);

        $this->assertSame(['S' => [620], 'A' => [], 'B' => [], 'C' => [], 'D' => [], 'F' => []], $this->tierList());

        $this->actingAs($this->bruno)
            ->get(route('games.show', $this->portal))
            ->assertInertia(fn (Assert $page) => $page->where('lists', ['tier' => 'S', 'backlog' => null]));

        $this->actingAs($this->bruno)
            ->get(route('games.index'))
            ->assertInertia(fn (Assert $page) => $page->where('tiers', ['620' => 'S']));

        $this->actingAs($this->leticia)->put(route('games.tier', $this->portal), ['tier' => 'F'])->assertNotFound();
        $this->actingAs($this->bruno)->put(route('games.tier', $this->portal), ['tier' => 'X'])->assertSessionHasErrors('tier');
    }

    public function test_games_join_the_backlog_at_the_end_of_the_queue(): void
    {
        $this->actingAs($this->bruno)
            ->from(route('games.show', $this->hades))
            ->post(route('games.backlog.store', $this->hades))
            ->assertRedirect(route('games.show', $this->hades));
        $this->actingAs($this->bruno)->post(route('games.backlog.store', $this->celeste));
        // Already on the list: stays as it is.
        $this->actingAs($this->bruno)->post(route('games.backlog.store', $this->hades));

        $entries = SteamBacklogEntry::query()->orderBy('position')->get();
        $this->assertSame(['Hades', 'Celeste'], $entries->pluck('name')->all());
        $this->assertSame([0, 1], $entries->pluck('position')->all());
        $this->assertSame(BacklogStatus::Queued, $entries[0]->status);
        $this->assertSame(4_200, $entries[0]->playtime_at_add);
        $this->assertSame('2026-11-20 10:00:00', $entries[0]->added_at->toDateTimeString());

        $this->actingAs($this->leticia)->post(route('games.backlog.store', $this->portal))->assertNotFound();
    }

    public function test_several_games_join_the_queue_at_once_in_the_order_picked(): void
    {
        $this->actingAs($this->bruno)->post(route('games.backlog.store', $this->hades));

        $this->actingAs($this->bruno)
            ->from(route('games.backlog'))
            ->post(route('games.backlog.add'), ['game_ids' => [$this->celeste->id, $this->hades->id, $this->portal->id]])
            ->assertRedirect(route('games.backlog'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '2 jogos entraram na lista para zerar.');

        $queue = SteamBacklogEntry::query()->orderBy('position')->get();
        // Hades was already queued: it keeps its place.
        $this->assertSame(['Hades', 'Celeste', 'Portal 2'], $queue->pluck('name')->all());
        $this->assertSame([BacklogStatus::Queued], $queue->pluck('status')->unique()->values()->all());
        $this->assertSame(600, $queue[2]->playtime_at_add);
    }

    public function test_games_already_finished_go_straight_to_the_finished_ones(): void
    {
        $this->actingAs($this->bruno)->post(route('games.backlog.store', $this->hades));

        $this->actingAs($this->bruno)
            ->post(route('games.backlog.add'), ['game_ids' => [$this->portal->id, $this->hades->id], 'finished' => true, 'finished_on' => '2025-03-14'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '2 jogos marcados como zerados.');

        $portal = SteamBacklogEntry::query()->where('app_id', 620)->sole();
        $this->assertSame(BacklogStatus::Completed, $portal->status);
        $this->assertSame('2025-03-14 12:00:00', $portal->completed_at?->toDateTimeString());
        $this->assertSame(600, $portal->playtime_at_completion);
        // Already queued, now finished too.
        $this->assertSame(BacklogStatus::Completed, SteamBacklogEntry::query()->where('app_id', 1145360)->sole()->status);

        // Without a day: finished now.
        $this->actingAs($this->bruno)->post(route('games.backlog.add'), ['game_ids' => [$this->celeste->id], 'finished' => true]);

        $this->assertSame('2026-11-20 10:00:00', SteamBacklogEntry::query()->where('app_id', 504230)->sole()->completed_at?->toDateTimeString());
    }

    public function test_adding_several_games_is_validated(): void
    {
        $outsider = $this->connectSteam($this->leticia, self::LETICIA_STEAM_ID);
        $hers = SteamGame::factory()->for($outsider, 'account')->create(['app_id' => 999]);

        $this->actingAs($this->bruno)->post(route('games.backlog.add'), ['game_ids' => [$this->portal->id, $hers->id]])->assertSessionHasErrors('game_ids');
        $this->actingAs($this->bruno)->post(route('games.backlog.add'), ['game_ids' => []])->assertSessionHasErrors('game_ids');
        $this->actingAs($this->bruno)->post(route('games.backlog.add'), ['game_ids' => [$this->portal->id], 'finished' => true, 'finished_on' => '2026-11-21'])->assertSessionHasErrors('finished_on');
        $this->actingAs($this->bruno)->post(route('games.backlog.add'), ['game_ids' => [$this->portal->id], 'finished' => true, 'finished_on' => '14/03/2025'])->assertSessionHasErrors('finished_on');

        $this->assertDatabaseCount('steam_backlog_entries', 0);
    }

    public function test_a_backlog_game_is_started_finished_and_queued_again(): void
    {
        $this->actingAs($this->bruno)->post(route('games.backlog.store', $this->hades));
        $this->actingAs($this->bruno)->post(route('games.backlog.store', $this->celeste));
        $entry = SteamBacklogEntry::query()->where('app_id', 1145360)->sole();

        Date::setTestNow('2026-11-21 20:00:00');
        $this->actingAs($this->bruno)->put(route('games.backlog.update', $entry), ['status' => 'playing'])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-21 20:00:00', $entry->refresh()->started_at?->toDateTimeString());

        Date::setTestNow('2026-11-30 23:00:00');
        $this->hades->update(['playtime_minutes' => 5_100]);
        $this->actingAs($this->bruno)->put(route('games.backlog.update', $entry), ['status' => 'completed']);

        $entry->refresh();
        $this->assertSame(BacklogStatus::Completed, $entry->status);
        $this->assertSame('2026-11-30 23:00:00', $entry->completed_at?->toDateTimeString());
        $this->assertSame('2026-11-21 20:00:00', $entry->started_at?->toDateTimeString());
        $this->assertSame(5_100, $entry->playtime_at_completion);

        // Playing it again: back at the end of the queue, as if new.
        $this->actingAs($this->bruno)->put(route('games.backlog.update', $entry), ['status' => 'queued']);

        $entry->refresh();
        $this->assertSame(BacklogStatus::Queued, $entry->status);
        $this->assertNull($entry->started_at);
        $this->assertNull($entry->completed_at);
        $this->assertNull($entry->playtime_at_completion);
        $this->assertSame(2, $entry->position);

        $this->actingAs($this->bruno)->put(route('games.backlog.update', $entry), ['status' => 'zerado'])->assertSessionHasErrors('status');
    }

    public function test_the_queue_is_reordered(): void
    {
        foreach ([$this->portal, $this->hades, $this->celeste] as $game) {
            $this->actingAs($this->bruno)->post(route('games.backlog.store', $game));
        }

        $ids = SteamBacklogEntry::query()->orderBy('position')->pluck('id', 'app_id');

        $this->actingAs($this->bruno)
            ->put(route('games.backlog.order'), ['ids' => [$ids[504230], $ids[620]]])
            ->assertSessionHasNoErrors();

        // Left out: keeps its relative place, after the others.
        $this->assertSame([504230, 620, 1145360], SteamBacklogEntry::query()->orderBy('position')->pluck('app_id')->all());

        // Ids of someone else's entries are ignored.
        $other = SteamBacklogEntry::query()->create(['user_id' => $this->leticia->id, 'app_id' => 1, 'name' => 'Outro', 'status' => BacklogStatus::Queued, 'position' => 7, 'added_at' => now()]);
        $this->actingAs($this->bruno)->put(route('games.backlog.order'), ['ids' => [$other->id]]);

        $this->assertSame(7, $other->refresh()->position);
    }

    public function test_the_backlog_page_lists_playing_then_queue_then_finished(): void
    {
        $make = fn (SteamGame $game, BacklogStatus $status, int $position, ?string $completed = null) => SteamBacklogEntry::query()->create([
            'user_id' => $this->bruno->id, 'app_id' => $game->app_id, 'name' => $game->name, 'status' => $status,
            'position' => $position, 'playtime_at_add' => 100, 'added_at' => '2026-11-01 10:00:00',
            'completed_at' => $completed, 'playtime_at_completion' => $completed ? 700 : null,
        ]);
        $make($this->portal, BacklogStatus::Completed, 0, '2026-11-10 10:00:00');
        $make($this->celeste, BacklogStatus::Queued, 3);
        $make($this->hades, BacklogStatus::Playing, 0);
        SteamBacklogEntry::query()->create(['user_id' => $this->bruno->id, 'app_id' => 4000, 'name' => 'Fora da biblioteca', 'status' => BacklogStatus::Queued, 'position' => 1, 'added_at' => now()]);
        SteamTierEntry::query()->create(['user_id' => $this->bruno->id, 'app_id' => 620, 'name' => 'Portal 2', 'tier' => Tier::A, 'position' => 0]);

        $this->actingAs($this->bruno)
            ->get(route('games.backlog'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('games/backlog', false)
                ->where('entries', fn ($entries) => collect($entries)->pluck('name')->all() === ['Hades', 'Fora da biblioteca', 'Celeste', 'Portal 2'])
                ->where('entries.0.status', 'playing')
                ->where('entries.0.playtime_minutes', 4_200)
                ->where('entries.0.game_id', $this->hades->id)
                ->where('entries.1.game_id', null)
                ->where('entries.1.playtime_minutes', null)
                ->where('entries.3.tier', 'A')
                ->where('entries.3.playtime_at_completion', 700)
                ->where('entries.3.completed_at', '2026-11-10T10:00:00-03:00')
                ->has('library', 3));
    }

    public function test_backlog_entries_of_another_user_are_not_found(): void
    {
        $this->actingAs($this->bruno)->post(route('games.backlog.store', $this->hades));
        $entry = SteamBacklogEntry::query()->sole();

        $this->actingAs($this->leticia)->put(route('games.backlog.update', $entry), ['status' => 'completed'])->assertNotFound();
        $this->actingAs($this->leticia)->delete(route('games.backlog.destroy', $entry))->assertNotFound();

        $this->actingAs($this->bruno)->delete(route('games.backlog.destroy', $entry))->assertRedirect();
        $this->assertModelMissing($entry);
    }

    public function test_the_lists_outlive_disconnecting_steam(): void
    {
        $this->actingAs($this->bruno)->put(route('games.tiers.update'), ['tiers' => ['S' => [620]]]);
        $this->actingAs($this->bruno)->post(route('games.backlog.store', $this->hades));

        $this->actingAs($this->bruno)->delete(route('games.steam.destroy'));
        $this->bruno->refresh(); // A real request loads the user afresh.

        $this->assertDatabaseCount('steam_tier_entries', 1);
        $this->assertDatabaseCount('steam_backlog_entries', 1);
        $this->actingAs($this->bruno)->get(route('games.tiers'))->assertRedirect(route('games.index'));
        $this->actingAs($this->bruno)->get(route('games.backlog'))->assertRedirect(route('games.index'));

        // Connected again (library not synced yet): the lists show the names they kept.
        $this->connectSteam($this->bruno, self::BRUNO_STEAM_ID);
        $this->bruno->refresh();

        $this->actingAs($this->bruno)
            ->get(route('games.tiers'))
            ->assertInertia(fn (Assert $page) => $page->where('tiers.S.0', ['app_id' => 620, 'name' => 'Portal 2', 'game_id' => null, 'playtime_minutes' => null, 'last_played_at' => null]));

        // A game still on the tier list can be moved even before the library is back.
        $this->actingAs($this->bruno)->put(route('games.tiers.update'), ['tiers' => ['A' => [620]]])->assertSessionHasNoErrors();
        $this->assertSame('A', SteamTierEntry::query()->sole()->tier->value);
    }
}
