<?php

namespace Tests\Feature\Games;

use App\Models\SteamAccount;
use App\Models\SteamAchievement;
use App\Models\SteamGame;
use App\Models\SteamPlaytimeDay;
use App\Models\SteamWishlistItem;
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
            'app_id' => 620, 'name' => 'Portal 2', 'playtime_minutes' => 600, 'playtime_2weeks_minutes' => 90, 'last_played_at' => '2026-11-19 21:30:00',
            'playtime_windows_minutes' => 500, 'playtime_linux_minutes' => 100, 'playtime_deck_minutes' => 40,
            'achievements_total' => 4, 'achievements_unlocked' => 4, 'achievements_synced_at' => now(), 'achievements_playtime_minutes' => 600, 'stats' => [],
        ]);
        $cs = $this->game([
            'app_id' => 730, 'name' => 'Counter-Strike 2', 'playtime_minutes' => 1_200,
            'achievements_total' => 10, 'achievements_unlocked' => 1, 'achievements_synced_at' => now(), 'achievements_playtime_minutes' => 1_200, 'stats' => [],
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
                ->where('summary.games', 4)
                ->where('summary.played', 3)
                ->where('summary.never_played', 1)
                ->where('summary.playtime_minutes', 1_830)
                ->where('summary.playtime_2weeks_minutes', 90)
                ->where('summary.recent_games', 1)
                ->where('summary.achievements_unlocked', 5)
                ->where('summary.achievements_total', 14)
                ->where('summary.perfect_games', 1)
                ->where('summary.platforms', ['windows' => 500, 'mac' => 0, 'linux' => 100, 'deck' => 40])
                ->has('games', 4)
                ->where('games.0', ['id' => $cs->id, 'app_id' => 730, 'name' => 'Counter-Strike 2', 'playtime_minutes' => 1_200, 'playtime_2weeks_minutes' => 0, 'last_played_at' => null])
                ->where('games.3.name', 'Portal')
                ->where('recent.0.name', 'Portal 2')
                ->where('recent.0.last_played_at', '2026-11-19T21:30:00-03:00')
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
                ->missing('recent_achievements'));

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
            ->assertInertia(function (Assert $page) {
                // The page shows at once, without asking Steam anything.
                $page->component('games/show', false)
                    ->where('game.name', 'Portal 2')
                    ->where('game.platforms.linux', 600)
                    ->where('game.store_url', 'https://store.steampowered.com/app/620/')
                    ->where('months.11', ['month' => '2026-11', 'minutes' => 90])
                    ->missing('store')
                    ->missing('progress');
                Http::assertNothingSent();

                $page->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('progress.achievements_total', 2)
                    ->where('progress.achievements_unlocked', 1)
                    ->where('progress.fetched', true)
                    ->where('progress.error', null)
                    ->where('progress.stats', [])
                    ->has('progress.achievements', 2)
                    ->where('progress.achievements.0.name', 'Bom Dia')
                    ->where('progress.achievements.0.icon_url', 'https://cdn.example.test/w.jpg')
                    ->where('progress.achievements.1.hidden', true)
                    ->where('progress.achievements.1.icon_url', 'https://cdn.example.test/s_gray.jpg')
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
            });

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'appdetails') && $request['l'] === 'brazilian' && $request['cc'] === 'br');

        // Nothing played since and the store page cached: no new requests.
        $sent = count(Http::recorded());
        $this->actingAs($this->bruno)
            ->get(route('games.show', $game))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->has('progress')->has('store')));
        $this->assertCount($sent, Http::recorded());
    }

    public function test_a_game_page_works_when_steam_fails(): void
    {
        $game = $this->game(['app_id' => 620, 'name' => 'Portal 2', 'playtime_minutes' => 600]);
        $this->steamApiStatus = 500;

        $this->actingAs($this->bruno)
            ->get(route('games.show', $game))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('store', null)
                ->where('progress.achievements', [])
                ->where('progress.fetched', false)
                ->where('progress.error', fn (string $error) => str_contains($error, 'erro 500'))));
    }

    public function test_a_game_page_shows_the_game_stats(): void
    {
        $game = $this->game([
            'app_id' => 730, 'name' => 'Counter-Strike 2', 'playtime_minutes' => 60,
            'achievements_total' => 0, 'achievements_unlocked' => 0, 'achievements_synced_at' => now(), 'achievements_playtime_minutes' => 60,
            'stats' => [['name' => 'total_kills', 'label' => 'Abates', 'value' => 1_234], ['name' => 'accuracy', 'label' => null, 'value' => 0.25]],
        ]);

        $this->actingAs($this->bruno)
            ->get(route('games.show', $game))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('progress', fn (Assert $reload) => $reload
                ->where('progress.stats.0', ['name' => 'total_kills', 'label' => 'Abates', 'value' => 1_234])
                ->where('progress.stats.1.value', 0.25)
                ->missing('store')));

        Http::assertNothingSent();
    }

    public function test_the_wishlist_page_lists_in_the_users_order_with_totals(): void
    {
        $owned = $this->game(['app_id' => 620, 'name' => 'Portal 2']);
        $item = fn (array $attributes) => SteamWishlistItem::query()->create(['steam_account_id' => $this->account->id, ...$attributes]);
        $item(['app_id' => 1, 'name' => 'Sem ordem antigo', 'priority' => 0, 'added_at' => '2026-01-01 10:00:00', 'price_cents' => 1_000]);
        $item(['app_id' => 2, 'name' => 'Segundo', 'priority' => 2, 'price_cents' => 5_000, 'original_price_cents' => 10_000, 'discount_percent' => 50, 'price_formatted' => 'R$50,00']);
        $item(['app_id' => 3, 'name' => 'Sem ordem novo', 'priority' => 0, 'added_at' => '2026-11-01 10:00:00', 'is_free' => true, 'price_cents' => null]);
        $item(['app_id' => 620, 'name' => 'Portal 2', 'priority' => 1, 'price_cents' => 3_399]);
        $item(['app_id' => 4, 'name' => 'Futuro', 'priority' => 3, 'coming_soon' => true]);

        $this->actingAs($this->bruno)
            ->get(route('games.wishlist'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('games/wishlist', false)
                ->where('account.persona_name', 'brunao')
                ->where('items', fn ($items) => collect($items)->pluck('name')->all() === ['Portal 2', 'Segundo', 'Futuro', 'Sem ordem novo', 'Sem ordem antigo'])
                ->where('items.0.owned_game_id', $owned->id)
                ->where('items.1.owned_game_id', null)
                ->where('items.1.discount_percent', 50)
                ->where('items.1.store_url', 'https://store.steampowered.com/app/2/')
                ->where('summary', [
                    'items' => 5, 'on_sale' => 1, 'coming_soon' => 1, 'free' => 1,
                    'total_cents' => 9_399, 'savings_cents' => 5_000,
                ]));

        Http::assertNothingSent();
    }

    public function test_wishlist_totals_need_prices_in_reais(): void
    {
        config(['services.steam.country' => 'us']);
        SteamWishlistItem::query()->create(['steam_account_id' => $this->account->id, 'app_id' => 1, 'name' => 'Jogo', 'price_cents' => 1_999]);

        $this->actingAs($this->bruno)
            ->get(route('games.wishlist'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_cents', null)
                ->where('summary.savings_cents', null));
    }

    public function test_the_friends_page_reads_steam_and_caches_it_for_a_few_minutes(): void
    {
        $this->game(['app_id' => 1145360, 'name' => 'Hades']);
        $this->steamFriendLists[self::BRUNO_STEAM_ID] = [
            ['steamid' => self::LETICIA_STEAM_ID, 'relationship' => 'friend', 'friend_since' => 1_600_000_000],
            ['steamid' => '76561198000000003', 'relationship' => 'friend', 'friend_since' => 1_500_000_000],
            ['steamid' => '76561198000000004', 'relationship' => 'friend', 'friend_since' => 1_400_000_000],
        ];
        $this->addSteamPlayer(self::LETICIA_STEAM_ID, ['personaname' => 'leti', 'gameid' => '1145360', 'gameextrainfo' => 'Hades']);
        $this->addSteamPlayer('76561198000000003', ['personaname' => 'carlos', 'personastate' => 0, 'gameid' => '99', 'gameextrainfo' => 'Outro jogo']);
        // 76561198000000004 deleted their account: left out.

        $this->actingAs($this->bruno)
            ->get(route('games.friends'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                // The tab shows at once; the list comes from Steam right after.
                $page->component('games/friends', false)->missing('list');
                Http::assertNothingSent();

                $page->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('list.private', false)
                    ->where('list.error', null)
                    ->has('list.friends', 2)
                    ->where('list.friends.0.persona_name', 'leti')
                    ->where('list.friends.0.playing', ['app_id' => 1_145_360, 'name' => 'Hades'])
                    ->where('list.friends.0.my_game_id', $this->account->games()->where('app_id', 1_145_360)->value('id'))
                    ->where('list.friends.0.friend_since', '2020-09-13T09:26:40-03:00')
                    ->where('list.friends.0.avatar_url', 'https://avatars.steamstatic.com/abc_full.jpg')
                    ->where('list.friends.1.persona_name', 'carlos')
                    ->where('list.friends.1.my_game_id', null));
            });

        $sent = count(Http::recorded());
        $this->actingAs($this->bruno)
            ->get(route('games.friends'))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->has('list.friends', 2)));
        $this->assertCount($sent, Http::recorded());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'GetPlayerSummaries')
            && $request['steamids'] === self::LETICIA_STEAM_ID.',76561198000000003,76561198000000004');
    }

    public function test_a_private_friends_list_is_explained(): void
    {
        $this->steamFriendLists[self::BRUNO_STEAM_ID] = null;

        $this->actingAs($this->bruno)
            ->get(route('games.friends'))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('list.private', true)
                ->where('list.friends', [])));
    }

    public function test_steam_failing_on_the_friends_page_is_explained_and_not_cached(): void
    {
        $this->steamApiStatus = 503;

        $this->actingAs($this->bruno)
            ->get(route('games.friends'))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('list.error', fn (string $error) => str_contains($error, 'erro 503'))));

        $this->steamApiStatus = null;

        $this->actingAs($this->bruno)
            ->get(route('games.friends'))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->where('list.error', null)));
    }

    public function test_a_refused_key_on_the_friends_page_is_not_mistaken_for_a_private_list(): void
    {
        $this->steamApiStatus = 403;

        $this->actingAs($this->bruno)
            ->get(route('games.friends'))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('list.private', false)
                ->where('list.error', fn (string $error) => str_contains($error, 'STEAM_API_KEY'))));
    }

    public function test_wishlist_and_friends_need_a_connected_account(): void
    {
        $this->actingAs($this->leticia)->get(route('games.wishlist'))->assertRedirect(route('games.index'));
        $this->actingAs($this->leticia)->get(route('games.friends'))->assertRedirect(route('games.index'));

        Http::assertNothingSent();
    }

    public function test_games_of_another_user_are_not_found(): void
    {
        $game = $this->game(['app_id' => 620, 'name' => 'Portal 2']);

        $this->actingAs($this->leticia)->get(route('games.show', $game))->assertNotFound();
        $this->actingAs($this->createOutsider())->get(route('games.show', $game))->assertNotFound();

        Http::assertNothingSent();
    }
}
