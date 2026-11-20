<?php

namespace Tests\Feature\Games;

use App\Models\SteamAccount;
use App\Models\SteamAchievement;
use App\Models\SteamGame;
use App\Models\SteamPlaytimeDay;
use App\Services\Steam\SteamProfileResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\FakesSteam;
use Tests\TestCase;

class SteamConnectionTest extends TestCase
{
    use CreatesHousehold, FakesSteam, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createHousehold();
        $this->configureSteam();
        $this->fakeSteam();
        $this->addSteamPlayer(self::BRUNO_STEAM_ID, ['personaname' => 'brunao']);
        $this->steamLibraries[self::BRUNO_STEAM_ID] = [$this->steamGame(620, 'Portal 2', 600)];
    }

    public function test_the_page_explains_how_to_configure_without_a_key(): void
    {
        config(['services.steam.key' => null]);

        $this->actingAs($this->bruno)
            ->get(route('games.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('games/index', false)
                ->where('configured', false)
                ->where('account', null));

        $this->actingAs($this->bruno)->get(route('games.steam.connect'))->assertRedirect(route('games.index'));

        Http::assertNothingSent();
    }

    public function test_signing_in_starts_on_steam_with_a_single_use_state(): void
    {
        $response = $this->actingAs($this->bruno)->get(route('games.steam.connect'));

        $state = session('games.steam_openid_state');
        $this->assertIsString($state);

        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://steamcommunity.com/openid/login?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('checkid_setup', $query['openid_mode']);
        $this->assertSame(route('games.steam.callback', ['state' => $state]), $query['openid_return_to']);
        $this->assertSame(url('/'), $query['openid_realm']);
        $this->assertSame('http://specs.openid.net/auth/2.0/identifier_select', $query['openid_claimed_id']);
    }

    public function test_the_callback_asks_steam_to_confirm_and_connects_the_account(): void
    {
        $returnTo = route('games.steam.callback', ['state' => 'abc']);

        $this->actingAs($this->bruno)
            ->withSession(['games.steam_openid_state' => 'abc'])
            ->get(route('games.steam.callback', ['state' => 'abc', ...$this->steamOpenIdResponse(self::BRUNO_STEAM_ID, $returnTo)]))
            ->assertRedirect(route('games.index'));

        $account = SteamAccount::query()->sole();
        $this->assertSame($this->bruno->id, $account->user_id);
        $this->assertSame(self::BRUNO_STEAM_ID, $account->steam_id);
        $this->assertSame('brunao', $account->persona_name);
        $this->assertNotNull($account->synced_at);
        $this->assertSame(['Portal 2'], $account->games()->pluck('name')->all());
        $this->assertNull(session('games.steam_openid_state'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://steamcommunity.com/openid/login'
            && $request->method() === 'POST'
            && $request['openid.mode'] === 'check_authentication'
            && $request['openid.sig'] === 'c2lnbmF0dXJl'
            && $request['openid.claimed_id'] === 'https://steamcommunity.com/openid/id/'.self::BRUNO_STEAM_ID);
    }

    public function test_a_sign_in_steam_does_not_confirm_is_refused(): void
    {
        $this->steamOpenIdValid = false;
        $returnTo = route('games.steam.callback', ['state' => 'abc']);

        $this->actingAs($this->bruno)
            ->withSession(['games.steam_openid_state' => 'abc'])
            ->get(route('games.steam.callback', ['state' => 'abc', ...$this->steamOpenIdResponse(self::BRUNO_STEAM_ID, $returnTo)]))
            ->assertRedirect(route('games.index'));

        $this->assertDatabaseCount('steam_accounts', 0);
    }

    public function test_forged_or_replayed_callbacks_never_reach_steam(): void
    {
        $returnTo = route('games.steam.callback', ['state' => 'abc']);
        $params = $this->steamOpenIdResponse(self::BRUNO_STEAM_ID, $returnTo);

        // No sign-in started in this session (or it was already used).
        $this->actingAs($this->bruno)
            ->get(route('games.steam.callback', ['state' => 'abc', ...$params]))
            ->assertRedirect(route('games.index'));

        // Another state than the one started.
        $this->actingAs($this->bruno)
            ->withSession(['games.steam_openid_state' => 'xyz'])
            ->get(route('games.steam.callback', ['state' => 'abc', ...$params]));

        // An identity outside steamcommunity.com.
        $this->actingAs($this->bruno)
            ->withSession(['games.steam_openid_state' => 'abc'])
            ->get(route('games.steam.callback', ['state' => 'abc', ...$params,
                'openid.claimed_id' => 'https://evil.example/openid/id/'.self::BRUNO_STEAM_ID,
                'openid.identity' => 'https://evil.example/openid/id/'.self::BRUNO_STEAM_ID,
            ]));

        // Signed for another return URL.
        $this->actingAs($this->bruno)
            ->withSession(['games.steam_openid_state' => 'abc'])
            ->get(route('games.steam.callback', ['state' => 'abc', ...$params, 'openid.return_to' => 'https://evil.example/']));

        // The identity left out of the signature.
        $this->actingAs($this->bruno)
            ->withSession(['games.steam_openid_state' => 'abc'])
            ->get(route('games.steam.callback', ['state' => 'abc', ...$params, 'openid.signed' => 'signed,op_endpoint,return_to,response_nonce']));

        $this->assertDatabaseCount('steam_accounts', 0);
        Http::assertNothingSent();
    }

    public function test_connecting_by_a_custom_profile_url(): void
    {
        $this->steamVanity['brunao'] = self::BRUNO_STEAM_ID;

        $this->actingAs($this->bruno)
            ->post(route('games.steam.store'), ['profile' => ' https://steamcommunity.com/id/brunao/ '])
            ->assertRedirect(route('games.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(self::BRUNO_STEAM_ID, $this->bruno->steamAccount()->value('steam_id'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'ResolveVanityURL') && $request['vanityurl'] === 'brunao');
    }

    public function test_an_unknown_profile_is_a_validation_error(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('games.steam.store'), ['profile' => 'ninguem-aqui'])
            ->assertSessionHasErrors('profile');

        $this->actingAs($this->bruno)
            ->post(route('games.steam.store'), ['profile' => '76561198999999999'])
            ->assertSessionHasErrors('profile');

        $this->actingAs($this->bruno)
            ->post(route('games.steam.store'), ['profile' => 'https://example.com/não é perfil'])
            ->assertSessionHasErrors('profile');

        $this->assertDatabaseCount('steam_accounts', 0);
    }

    /**
     * @return array<string, array{0: string, 1: string|null, 2: string|null}>
     */
    public static function profiles(): array
    {
        return [
            'steamid64' => ['76561198000000001', '76561198000000001', null],
            'profiles url' => ['https://steamcommunity.com/profiles/76561198000000001/', '76561198000000001', null],
            'id url' => ['steamcommunity.com/id/Bruno_123', null, 'Bruno_123'],
            'id url with query' => ['https://www.steamcommunity.com/id/bruno?l=brazilian', null, 'bruno'],
            'name alone' => ['bruno-b', null, 'bruno-b'],
            'profiles url with a name' => ['https://steamcommunity.com/profiles/bruno', null, null],
            'other site' => ['https://example.com/id/bruno', null, null],
            'too short' => ['b', null, null],
        ];
    }

    #[DataProvider('profiles')]
    public function test_profiles_are_read_from_ids_urls_and_names(string $input, ?string $steamId, ?string $vanity): void
    {
        $this->assertSame([$steamId, $vanity], SteamProfileResolver::parse($input));
    }

    public function test_a_steam_account_belongs_to_a_single_user(): void
    {
        $this->connectSteam($this->leticia, self::BRUNO_STEAM_ID);

        $this->actingAs($this->bruno)
            ->post(route('games.steam.store'), ['profile' => self::BRUNO_STEAM_ID])
            ->assertRedirect(route('games.index'));

        $this->assertNull($this->bruno->steamAccount);
        $this->assertSame(1, SteamAccount::query()->count());
    }

    public function test_connecting_another_account_replaces_the_previous_one(): void
    {
        $this->addSteamPlayer(self::LETICIA_STEAM_ID);
        $old = $this->connectSteam($this->bruno, self::LETICIA_STEAM_ID);
        $game = SteamGame::factory()->for($old, 'account')->create();

        $this->actingAs($this->bruno)->post(route('games.steam.store'), ['profile' => self::BRUNO_STEAM_ID]);

        $this->assertModelMissing($old);
        $this->assertModelMissing($game);
        $this->assertSame(self::BRUNO_STEAM_ID, $this->bruno->steamAccount()->value('steam_id'));
    }

    public function test_each_user_sees_only_their_own_account(): void
    {
        $this->connectSteam($this->bruno, self::BRUNO_STEAM_ID)->update(['persona_name' => 'brunao']);

        $this->actingAs($this->leticia)
            ->get(route('games.index'))
            ->assertInertia(fn (Assert $page) => $page->where('account', null));

        $this->actingAs($this->bruno)
            ->get(route('games.index'))
            ->assertInertia(fn (Assert $page) => $page->where('account.persona_name', 'brunao'));

        $this->actingAs($this->leticia)->post(route('games.steam.sync'))->assertNotFound();
        $this->actingAs($this->leticia)->delete(route('games.steam.destroy'))->assertNotFound();
        $this->assertDatabaseCount('steam_accounts', 1);
    }

    public function test_disconnecting_deletes_the_account_and_everything_synced(): void
    {
        $account = $this->connectSteam($this->bruno, self::BRUNO_STEAM_ID);
        $game = SteamGame::factory()->for($account, 'account')->create();
        $achievement = SteamAchievement::query()->create(['steam_game_id' => $game->id, 'api_name' => 'WIN', 'name' => 'Venceu']);
        $day = SteamPlaytimeDay::query()->create(['steam_account_id' => $account->id, 'steam_game_id' => $game->id, 'played_on' => '2026-11-19', 'minutes' => 30]);

        $this->actingAs($this->bruno)
            ->delete(route('games.steam.destroy'))
            ->assertRedirect(route('games.index'));

        $this->assertModelMissing($account);
        $this->assertModelMissing($game);
        $this->assertModelMissing($achievement);
        $this->assertModelMissing($day);
    }
}
