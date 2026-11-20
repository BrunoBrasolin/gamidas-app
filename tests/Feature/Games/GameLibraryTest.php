<?php

namespace Tests\Feature\Games;

use App\Models\SteamAccount;
use App\Models\SteamAchievement;
use App\Models\SteamGame;
use App\Models\SteamPlaytimeDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\FakesSteam;
use Tests\TestCase;

class GameLibraryTest extends TestCase
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
        $this->account = $this->connectSteam($this->bruno, self::BRUNO_STEAM_ID);
        $this->account->update(['persona_name' => 'brunao', 'level' => 12, 'checked_at' => now(), 'synced_at' => now()]);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function game(array $attributes): SteamGame
    {
        return SteamGame::factory()->for($this->account, 'account')->create($attributes);
    }

    public function test_the_page_shows_totals_history_and_every_game(): void
    {
        $portal = $this->game([
            'app_id' => 620, 'name' => 'Portal 2', 'playtime_minutes' => 600, 'playtime_2weeks_minutes' => 90,
            'playtime_windows_minutes' => 500, 'playtime_linux_minutes' => 100, 'playtime_deck_minutes' => 40,
            'achievements_total' => 4, 'achievements_unlocked' => 4, 'achievements_synced_at' => now(), 'achievements_playtime_minutes' => 600,
        ]);
        $cs = $this->game([
            'app_id' => 730, 'name' => 'Counter-Strike 2', 'playtime_minutes' => 1_200,
            'achievements_total' => 10, 'achievements_unlocked' => 1, 'achievements_synced_at' => now(), 'achievements_playtime_minutes' => 1_200,
        ]);
        $this->game(['app_id' => 400, 'name' => 'Portal', 'playtime_minutes' => 0]);
        $this->game(['app_id' => 220, 'name' => 'Half-Life 2', 'playtime_minutes' => 30]); // achievements still to fetch
        $this->game(['app_id' => 500, 'name' => 'Reembolsado', 'playtime_minutes' => 5, 'removed_at' => now()]);

        SteamPlaytimeDay::query()->create(['steam_account_id' => $this->account->id, 'steam_game_id' => $portal->id, 'played_on' => '2026-11-19', 'minutes' => 90]);
        SteamPlaytimeDay::query()->create(['steam_account_id' => $this->account->id, 'steam_game_id' => $cs->id, 'played_on' => '2026-11-19', 'minutes' => 30]);
        SteamPlaytimeDay::query()->create(['steam_account_id' => $this->account->id, 'steam_game_id' => $cs->id, 'played_on' => '2026-10-02', 'minutes' => 45]);
        SteamPlaytimeDay::query()->create(['steam_account_id' => $this->account->id, 'steam_game_id' => $cs->id, 'played_on' => '2025-10-31', 'minutes' => 999]); // before the 12 months
        SteamAchievement::query()->create(['steam_game_id' => $portal->id, 'api_name' => 'OLD', 'name' => 'Antiga', 'achieved' => true, 'unlocked_at' => '2026-11-01 10:00:00']);
        SteamAchievement::query()->create(['steam_game_id' => $cs->id, 'api_name' => 'NEW', 'name' => 'Nova', 'achieved' => true, 'unlocked_at' => '2026-11-19 21:00:00', 'global_percent_bp' => 520]);
        SteamAchievement::query()->create(['steam_game_id' => $cs->id, 'api_name' => 'LOCKED', 'name' => 'Bloqueada', 'achieved' => false]);

        $this->actingAs($this->bruno)
            ->get(route('games.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('games/index', false)
                ->where('configured', true)
                ->where('account.persona_name', 'brunao')
                ->where('account.level', 12)
                ->where('account.pending_achievements', 1)
                ->where('summary.games', 4)
                ->where('summary.played', 3)
                ->where('summary.never_played', 1)
                ->where('summary.playtime_minutes', 1_830)
                ->where('summary.playtime_2weeks_minutes', 90)
                ->where('summary.recent_games', 1)
                ->where('summary.achievements_unlocked', 5)
                ->where('summary.achievements_total', 14)
                ->where('summary.perfect_games', 1)
                ->where('summary.average_completion_bp', 5_500)
                ->where('summary.platforms', ['windows' => 500, 'mac' => 0, 'linux' => 100, 'deck' => 40])
                ->has('games', 4)
                ->where('games.0.name', 'Counter-Strike 2')
                ->where('games.0.header_url', 'https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps/730/header.jpg')
                ->where('games.3.name', 'Portal')
                ->where('recent.0.name', 'Portal 2')
                ->has('recent', 1)
                ->where('top.0.name', 'Counter-Strike 2')
                ->has('top', 3)
                ->has('months', 12)
                ->where('months.0.month', '2025-12')
                ->where('months.10', ['month' => '2026-10', 'minutes' => 45])
                ->where('months.11', ['month' => '2026-11', 'minutes' => 120])
                ->has('days', 30)
                ->where('days.28', ['date' => '2026-11-19', 'minutes' => 120])
                ->where('history_since', '2025-10-31')
                ->has('recent_achievements', 2)
                ->where('recent_achievements.0.name', 'Nova')
                ->where('recent_achievements.0.game', ['id' => $cs->id, 'name' => 'Counter-Strike 2'])
                ->where('recent_achievements.0.global_percent_bp', 520));

        Http::assertNothingSent();
    }

    public function test_a_game_page_fetches_stale_achievements_and_the_store_page(): void
    {
        $game = $this->game(['app_id' => 620, 'name' => 'Portal 2', 'playtime_minutes' => 600, 'playtime_linux_minutes' => 600]);
        SteamPlaytimeDay::query()->create(['steam_account_id' => $this->account->id, 'steam_game_id' => $game->id, 'played_on' => '2026-11-19', 'minutes' => 90]);
        $this->steamSchemas[620] = [
            ['name' => 'WAKE_UP', 'displayName' => 'Bom Dia', 'icon' => 'https://cdn.example.test/w.jpg', 'icongray' => 'https://cdn.example.test/w_gray.jpg', 'hidden' => 0],
            ['name' => 'SECRET', 'displayName' => 'Segredo', 'icon' => 'https://cdn.example.test/s.jpg', 'icongray' => 'https://cdn.example.test/s_gray.jpg', 'hidden' => 1],
        ];
        $this->steamUnlocks[self::BRUNO_STEAM_ID.':620'] = [
            ['apiname' => 'WAKE_UP', 'achieved' => 1, 'unlocktime' => 1_790_000_000],
            ['apiname' => 'SECRET', 'achieved' => 0, 'unlocktime' => 0],
        ];
        $this->steamStore[620] = [
            'type' => 'game',
            'name' => 'Portal 2',
            'short_description' => 'A &quot;Iniciativa de Testes Perpétuos&quot; foi expandida.',
            'header_image' => 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/620/abc/header.jpg?t=1',
            'website' => 'http://www.thinkwithportals.com/',
            'developers' => ['Valve'],
            'publishers' => ['Valve'],
            'release_date' => ['coming_soon' => false, 'date' => '18/abr./2011'],
            'genres' => [['id' => '1', 'description' => 'Ação'], ['id' => '25', 'description' => 'Aventura']],
            'categories' => [['id' => 2, 'description' => 'Um jogador'], ['id' => 9, 'description' => 'Cooperativo']],
            'metacritic' => ['score' => 95, 'url' => 'https://www.metacritic.com/game/pc/portal-2'],
            'price_overview' => ['currency' => 'BRL', 'final_formatted' => 'R$ 33,99', 'discount_percent' => 0],
            'is_free' => false,
            'platforms' => ['windows' => true, 'mac' => false, 'linux' => true],
            'controller_support' => 'full',
            'recommendations' => ['total' => 393_265],
            'screenshots' => [['id' => 0, 'path_thumbnail' => 'https://cdn.example.test/1t.jpg', 'path_full' => 'https://cdn.example.test/1.jpg']],
        ];

        $this->actingAs($this->bruno)
            ->get(route('games.show', $game))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('games/show', false)
                ->where('game.name', 'Portal 2')
                ->where('game.achievements_total', 2)
                ->where('game.achievements_unlocked', 1)
                ->where('game.platforms.linux', 600)
                ->where('game.store_url', 'https://store.steampowered.com/app/620/')
                ->where('achievements_error', null)
                ->has('achievements', 2)
                ->where('achievements.0.name', 'Bom Dia')
                ->where('achievements.0.icon_url', 'https://cdn.example.test/w.jpg')
                ->where('achievements.1.hidden', true)
                ->where('achievements.1.icon_url', 'https://cdn.example.test/s_gray.jpg')
                ->where('months.11', ['month' => '2026-11', 'minutes' => 90])
                ->where('store.description', 'A "Iniciativa de Testes Perpétuos" foi expandida.')
                ->where('store.website', 'https://www.thinkwithportals.com/')
                ->where('store.genres', ['Ação', 'Aventura'])
                ->where('store.categories', ['Um jogador', 'Cooperativo'])
                ->where('store.metacritic.score', 95)
                ->where('store.price', 'R$ 33,99')
                ->where('store.platforms', ['windows', 'linux'])
                ->where('store.controller_support', 'full')
                ->where('store.recommendations', 393_265)
                ->where('store.release_date', '18/abr./2011')
                ->where('store.screenshots.0.full_url', 'https://cdn.example.test/1.jpg'));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'appdetails') && $request['l'] === 'brazilian' && $request['cc'] === 'br');

        // Nothing played since and the store page cached: no new requests.
        $sent = count(Http::recorded());
        $this->actingAs($this->bruno)->get(route('games.show', $game))->assertOk();
        $this->assertCount($sent, Http::recorded());
    }

    public function test_a_game_page_works_when_steam_fails(): void
    {
        $game = $this->game(['app_id' => 620, 'name' => 'Portal 2', 'playtime_minutes' => 600]);
        $this->steamApiStatus = 500;

        $this->actingAs($this->bruno)
            ->get(route('games.show', $game))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('store', null)
                ->where('achievements', [])
                ->where('achievements_error', fn (string $error) => str_contains($error, 'erro 500')));
    }

    public function test_games_of_another_user_are_not_found(): void
    {
        $game = $this->game(['app_id' => 620, 'name' => 'Portal 2']);

        $this->actingAs($this->leticia)->get(route('games.show', $game))->assertNotFound();
        $this->actingAs($this->createOutsider())->get(route('games.show', $game))->assertNotFound();

        Http::assertNothingSent();
    }
}
