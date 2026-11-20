<?php

namespace Tests\Feature\Games;

use App\Models\SteamAccount;
use App\Models\SteamAchievement;
use App\Models\SteamGame;
use App\Models\SteamPlaytimeDay;
use App\Services\Steam\SteamException;
use App\Services\Steam\SteamSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\FakesSteam;
use Tests\TestCase;

class SteamSyncTest extends TestCase
{
    use CreatesHousehold, FakesSteam, RefreshDatabase;

    private SteamAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-20 10:00:00');
        $this->createHousehold();
        $this->configureSteam();
        $this->fakeSteam();
        $this->addSteamPlayer(self::BRUNO_STEAM_ID, ['personaname' => 'brunao', 'gameid' => '620', 'gameextrainfo' => 'Portal 2']);
        $this->account = $this->connectSteam($this->bruno, self::BRUNO_STEAM_ID);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function sync(): void
    {
        app(SteamSyncService::class)->sync($this->account);
    }

    /**
     * A Unix timestamp of a São Paulo local time.
     */
    private static function at(string $local): int
    {
        return CarbonImmutable::parse($local, 'America/Sao_Paulo')->getTimestamp();
    }

    public function test_sync_stores_the_profile_level_and_library(): void
    {
        $this->steamLibraries[self::BRUNO_STEAM_ID] = [
            $this->steamGame(620, 'Portal 2', 600, ['playtime_2weeks' => 45, 'playtime_linux_forever' => 100, 'playtime_deck_forever' => 60, 'rtime_last_played' => self::at('2026-11-19 22:00')]),
            $this->steamGame(400, 'Portal', 0, ['img_icon_url' => '']),
        ];

        $this->sync();

        $this->account->refresh();
        $this->assertSame('brunao', $this->account->persona_name);
        $this->assertSame('BR', $this->account->country_code);
        $this->assertTrue($this->account->public_profile);
        $this->assertTrue($this->account->public_games);
        $this->assertSame(1, $this->account->persona_state);
        $this->assertSame(620, $this->account->playing_app_id);
        $this->assertSame('Portal 2', $this->account->playing_name);
        $this->assertSame('2010-01-01', $this->account->account_created_at?->setTimezone('UTC')->toDateString());
        $this->assertSame(12, $this->account->level);
        $this->assertSame(1_250, $this->account->xp);
        $this->assertSame(1_200, $this->account->xp_level_start);
        $this->assertSame(150, $this->account->xp_to_next_level);
        $this->assertSame(3, $this->account->badges_count);
        $this->assertSame('2026-11-20 10:00:00', $this->account->synced_at?->toDateTimeString());
        $this->assertNull($this->account->sync_error);

        $portal2 = $this->account->games()->where('app_id', 620)->sole();
        $this->assertSame('Portal 2', $portal2->name);
        $this->assertSame(600, $portal2->playtime_minutes);
        $this->assertSame(45, $portal2->playtime_2weeks_minutes);
        $this->assertSame(100, $portal2->playtime_linux_minutes);
        $this->assertSame(60, $portal2->playtime_deck_minutes);
        $this->assertSame('2026-11-19 22:00:00', $portal2->last_played_at?->toDateTimeString());
        $this->assertSame(str_repeat('a', 40), $portal2->icon_hash);
        $this->assertNull($this->account->games()->where('app_id', 400)->value('icon_hash'));

        // The first sync is only the starting point of the daily history.
        $this->assertDatabaseCount('steam_playtime_days', 0);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'GetOwnedGames')
            && $request['include_appinfo'] === 1
            && $request['include_played_free_games'] === 1
            && $request['language'] === 'brazilian'
            && $request['key'] === self::STEAM_KEY);
    }

    public function test_playtime_growth_between_syncs_is_credited_to_the_day_played(): void
    {
        $this->steamLibraries[self::BRUNO_STEAM_ID] = [$this->steamGame(620, 'Portal 2', 600, ['rtime_last_played' => self::at('2026-11-18 20:00')])];
        $this->sync();

        // Played last night until 23:30; synced the next morning.
        Date::setTestNow('2026-11-21 08:00:00');
        $this->steamLibraries[self::BRUNO_STEAM_ID] = [
            $this->steamGame(620, 'Portal 2', 690, ['rtime_last_played' => self::at('2026-11-20 23:30')]),
            // Bought and played since the last sync.
            $this->steamGame(730, 'Counter-Strike 2', 30, ['rtime_last_played' => self::at('2026-11-21 07:00')]),
        ];
        $this->sync();

        // Played again; a later sync on the same day adds to the same row.
        Date::setTestNow('2026-11-21 12:00:00');
        $this->steamLibraries[self::BRUNO_STEAM_ID][1]['playtime_forever'] = 50;
        $this->steamLibraries[self::BRUNO_STEAM_ID][1]['rtime_last_played'] = self::at('2026-11-21 11:50');
        $this->sync();

        $days = SteamPlaytimeDay::query()->with('game')->orderBy('played_on')->get()
            ->map(fn (SteamPlaytimeDay $day) => [$day->game->name, $day->played_on->toDateString(), $day->minutes])
            ->all();

        $this->assertSame([
            ['Portal 2', '2026-11-20', 90],
            ['Counter-Strike 2', '2026-11-21', 50],
        ], $days);
    }

    public function test_growth_is_never_more_than_the_time_between_syncs(): void
    {
        $this->steamLibraries[self::BRUNO_STEAM_ID] = [$this->steamGame(620, 'Portal 2', 600)];
        $this->sync();

        // An hour later the total jumped ten hours (a game played elsewhere
        // and only now counted, say): only the hour can have been played.
        Date::setTestNow('2026-11-20 11:00:00');
        $this->steamLibraries[self::BRUNO_STEAM_ID] = [$this->steamGame(620, 'Portal 2', 1_200, ['rtime_last_played' => self::at('2026-11-20 10:50')])];
        $this->sync();

        $this->assertSame(65, SteamPlaytimeDay::query()->sole()->minutes);
    }

    public function test_private_game_details_keep_the_library_and_say_why(): void
    {
        $this->steamLibraries[self::BRUNO_STEAM_ID] = [$this->steamGame(620, 'Portal 2', 600)];
        $this->sync();

        Date::setTestNow('2026-11-20 12:00:00');
        $this->steamLibraries[self::BRUNO_STEAM_ID] = null;
        $this->sync();

        $this->account->refresh();
        $this->assertFalse($this->account->public_games);
        $this->assertStringContainsString('Detalhes do jogo', (string) $this->account->sync_error);
        $this->assertSame('2026-11-20 10:00:00', $this->account->synced_at?->toDateTimeString());
        $this->assertSame('2026-11-20 12:00:00', $this->account->checked_at?->toDateTimeString());
        $this->assertSame(1, $this->account->games()->inLibrary()->count());
    }

    public function test_games_that_leave_the_library_keep_their_history_but_are_hidden(): void
    {
        $this->steamLibraries[self::BRUNO_STEAM_ID] = [$this->steamGame(620, 'Portal 2', 600), $this->steamGame(400, 'Portal', 10)];
        $this->sync();

        $this->steamLibraries[self::BRUNO_STEAM_ID] = [$this->steamGame(620, 'Portal 2', 600)];
        $this->sync();

        $portal = $this->account->games()->where('app_id', 400)->sole();
        $this->assertNotNull($portal->removed_at);
        $this->assertSame(['Portal 2'], $this->account->games()->inLibrary()->pluck('name')->all());

        // Back in the library (bought again).
        $this->steamLibraries[self::BRUNO_STEAM_ID][] = $this->steamGame(400, 'Portal', 10);
        $this->sync();

        $this->assertNull($portal->refresh()->removed_at);
    }

    public function test_a_failed_sync_is_recorded_and_thrown(): void
    {
        $this->steamApiStatus = 503;

        try {
            $this->sync();
            $this->fail('The sync should have failed.');
        } catch (SteamException $exception) {
            $this->assertSame(503, $exception->status);
        }

        $this->account->refresh();
        $this->assertStringContainsString('erro 503', (string) $this->account->sync_error);
        $this->assertNull($this->account->synced_at);
        $this->assertStringNotContainsString(self::STEAM_KEY, (string) $this->account->sync_error);
    }

    public function test_achievements_come_with_names_icons_rarity_and_unlock_times(): void
    {
        $game = SteamGame::factory()->for($this->account, 'account')->create(['app_id' => 620, 'playtime_minutes' => 600]);
        $this->steamSchemas[620] = [
            ['name' => 'WAKE_UP', 'displayName' => 'Bom Dia, Flor do Dia', 'description' => 'Sobreviva ao transporte.', 'icon' => 'http://cdn.example.test/wake.jpg', 'icongray' => 'https://cdn.example.test/wake_gray.jpg', 'hidden' => 0],
            ['name' => 'SECRET', 'displayName' => 'Segredo', 'description' => '', 'icon' => 'https://cdn.example.test/s.jpg', 'icongray' => 'https://cdn.example.test/s_gray.jpg', 'hidden' => 1],
            ['name' => 'LASER', 'displayName' => 'Laser', 'icon' => 'https://cdn.example.test/l.jpg', 'icongray' => 'https://cdn.example.test/l_gray.jpg', 'hidden' => 0],
        ];
        $this->steamUnlocks[self::BRUNO_STEAM_ID.':620'] = [
            ['apiname' => 'WAKE_UP', 'achieved' => 1, 'unlocktime' => self::at('2026-11-19 21:15')],
            ['apiname' => 'SECRET', 'achieved' => 0, 'unlocktime' => 0],
            ['apiname' => 'LASER', 'achieved' => 1, 'unlocktime' => self::at('2026-11-19 21:40')],
        ];
        $this->steamPercentages[620] = [['name' => 'WAKE_UP', 'percent' => '64.7'], ['name' => 'SECRET', 'percent' => '3.25']];

        $synced = app(SteamSyncService::class)->syncAchievements($this->account);

        $this->assertSame(1, $synced);
        $game->refresh();
        $this->assertSame(3, $game->achievements_total);
        $this->assertSame(2, $game->achievements_unlocked);
        $this->assertSame(600, $game->achievements_playtime_minutes);
        $this->assertFalse($game->achievementsStale());

        $wake = SteamAchievement::query()->where('api_name', 'WAKE_UP')->sole();
        $this->assertSame('Bom Dia, Flor do Dia', $wake->name);
        $this->assertSame('https://cdn.example.test/wake.jpg', $wake->icon_url);
        $this->assertSame(6_470, $wake->global_percent_bp);
        $this->assertTrue($wake->achieved);
        $this->assertSame('2026-11-19 21:15:00', $wake->unlocked_at?->toDateTimeString());
        $this->assertSame(0, $wake->position);

        $secret = SteamAchievement::query()->where('api_name', 'SECRET')->sole();
        $this->assertTrue($secret->hidden);
        $this->assertFalse($secret->achieved);
        $this->assertNull($secret->unlocked_at);
        $this->assertNull($secret->description);
        $this->assertSame(325, $secret->global_percent_bp);
        $this->assertNull(SteamAchievement::query()->where('api_name', 'LASER')->value('global_percent_bp'));

        // Nothing played since: nothing to fetch.
        $this->assertSame(0, app(SteamSyncService::class)->syncAchievements($this->account));

        // Played again: fetched again, with the schema from the cache.
        $game->update(['playtime_minutes' => 700]);
        array_pop($this->steamSchemas[620]);
        app(SteamSyncService::class)->syncAchievements($this->account);

        $this->assertSame(3, $game->refresh()->achievements_total);
        $this->assertSame(700, $game->achievements_playtime_minutes);
        Http::assertSentCount(4); // Schema, unlocks and percentages; then only the unlocks.

        // Once the cache expires, an achievement that left the schema goes away.
        Cache::flush();
        $game->update(['playtime_minutes' => 800]);
        app(SteamSyncService::class)->syncAchievements($this->account);

        $this->assertSame(2, $game->refresh()->achievements_total);
        $this->assertSame(['WAKE_UP', 'SECRET'], $game->achievements()->orderBy('position')->pluck('api_name')->all());
    }

    public function test_games_without_achievements_are_marked_as_such(): void
    {
        $game = SteamGame::factory()->for($this->account, 'account')->create(['app_id' => 999, 'playtime_minutes' => 60]);

        app(SteamSyncService::class)->syncAchievements($this->account);

        $game->refresh();
        $this->assertSame(0, $game->achievements_total);
        $this->assertSame(0, $game->achievements_unlocked);
        $this->assertNotNull($game->achievements_synced_at);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'GetPlayerAchievements'));
    }

    public function test_achievements_of_a_private_profile_fail_with_the_reason(): void
    {
        $this->steamPlayers[self::BRUNO_STEAM_ID]['communityvisibilitystate'] = 1;
        SteamGame::factory()->for($this->account, 'account')->create(['app_id' => 620, 'playtime_minutes' => 60]);
        $this->steamSchemas[620] = [['name' => 'WAKE_UP', 'displayName' => 'Bom Dia', 'hidden' => 0]];

        $this->expectException(SteamException::class);
        $this->expectExceptionMessage('privado');

        app(SteamSyncService::class)->syncAchievements($this->account);
    }

    public function test_the_command_syncs_stale_accounts_and_pending_achievements(): void
    {
        $this->steamLibraries[self::BRUNO_STEAM_ID] = [$this->steamGame(620, 'Portal 2', 600)];
        $this->steamSchemas[620] = [['name' => 'WAKE_UP', 'displayName' => 'Bom Dia', 'hidden' => 0]];
        $this->steamUnlocks[self::BRUNO_STEAM_ID.':620'] = [['apiname' => 'WAKE_UP', 'achieved' => 1, 'unlocktime' => self::at('2026-11-19 21:15')]];
        $this->account->update(['checked_at' => '2026-11-20 08:00:00', 'synced_at' => null]);

        $fresh = $this->connectSteam($this->leticia, self::LETICIA_STEAM_ID);
        $fresh->update(['checked_at' => '2026-11-20 09:30:00']);

        $this->artisan('steam:sync')->assertSuccessful();

        $this->assertSame('2026-11-20 10:00:00', $this->account->refresh()->checked_at?->toDateTimeString());
        $this->assertSame(1, $this->account->games()->sole()->achievements_unlocked);
        // Checked half an hour ago: left alone.
        $this->assertSame('2026-11-20 09:30:00', $fresh->refresh()->checked_at?->toDateTimeString());
        Http::assertNotSent(fn (Request $request) => ($request['steamids'] ?? $request['steamid'] ?? null) === self::LETICIA_STEAM_ID);
    }

    public function test_the_command_does_nothing_without_a_key(): void
    {
        config(['services.steam.key' => null]);

        $this->artisan('steam:sync')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_syncing_by_hand_refreshes_and_reports(): void
    {
        $this->steamLibraries[self::BRUNO_STEAM_ID] = [$this->steamGame(620, 'Portal 2', 600)];

        $this->actingAs($this->bruno)
            ->from(route('games.index'))
            ->post(route('games.steam.sync'))
            ->assertRedirect(route('games.index'));

        $this->assertSame(1, $this->account->games()->count());

        $this->steamApiStatus = 403;

        $this->actingAs($this->bruno)
            ->from(route('games.index'))
            ->post(route('games.steam.sync'))
            ->assertRedirect(route('games.index'));

        $this->assertStringContainsString('STEAM_API_KEY', (string) $this->account->refresh()->sync_error);
    }
}
