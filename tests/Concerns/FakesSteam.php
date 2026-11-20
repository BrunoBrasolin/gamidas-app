<?php

namespace Tests\Concerns;

use App\Models\SteamAccount;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A fake Steam (Web API, store API and OpenID sign-in) through Http::fake,
 * serving the players, libraries and achievements set in its properties.
 * Nothing here is real data.
 */
trait FakesSteam
{
    protected const STEAM_KEY = 'test-steam-key';

    protected const BRUNO_STEAM_ID = '76561198000000001';

    protected const LETICIA_STEAM_ID = '76561198000000002';

    /**
     * @var array<string, array<string, mixed>> Players by SteamID64.
     */
    protected array $steamPlayers = [];

    /**
     * @var array<string, array<string, mixed>> GetBadges responses by SteamID64.
     */
    protected array $steamBadges = [];

    /**
     * @var array<string, list<array<string, mixed>>|null> Libraries by SteamID64; null = private.
     */
    protected array $steamLibraries = [];

    /**
     * @var array<string, string> SteamID64 by custom URL name.
     */
    protected array $steamVanity = [];

    /**
     * @var array<int, list<array<string, mixed>>> Achievement schemas by app id.
     */
    protected array $steamSchemas = [];

    /**
     * @var array<string, list<array<string, mixed>>|null> Player achievements by "steamid:appid"; null = no stats.
     */
    protected array $steamUnlocks = [];

    /**
     * @var array<int, list<array{name: string, percent: string}>> Global percentages by app id.
     */
    protected array $steamPercentages = [];

    /**
     * @var array<int, array<string, mixed>> Store data by app id.
     */
    protected array $steamStore = [];

    /** Whether the OpenID check_authentication answers is_valid:true. */
    protected bool $steamOpenIdValid = true;

    /** HTTP status every Web API call answers with instead, when set. */
    protected ?int $steamApiStatus = null;

    protected function configureSteam(): void
    {
        config([
            'services.steam.key' => self::STEAM_KEY,
            'services.steam.language' => 'brazilian',
            'services.steam.country' => 'br',
        ]);
    }

    protected function fakeSteam(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->steamResponse($request));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function addSteamPlayer(string $steamId, array $overrides = []): void
    {
        $this->steamPlayers[$steamId] = [
            'steamid' => $steamId,
            'communityvisibilitystate' => 3,
            'profilestate' => 1,
            'personaname' => 'jogador-'.substr($steamId, -2),
            'profileurl' => "https://steamcommunity.com/profiles/{$steamId}/",
            'avatarfull' => 'https://avatars.steamstatic.com/abc_full.jpg',
            'personastate' => 1,
            'timecreated' => 1_262_304_000, // 2010-01-01
            'lastlogoff' => 1_790_000_000,
            'loccountrycode' => 'BR',
            ...$overrides,
        ];
        $this->steamBadges[$steamId] ??= [
            'badges' => [['badgeid' => 1], ['badgeid' => 2], ['badgeid' => 13]],
            'player_xp' => 1_250,
            'player_level' => 12,
            'player_xp_needed_to_level_up' => 150,
            'player_xp_needed_current_level' => 1_200,
        ];
        $this->steamLibraries[$steamId] ??= [];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function steamGame(int $appId, string $name, int $minutes, array $overrides = []): array
    {
        return [
            'appid' => $appId,
            'name' => $name,
            'playtime_forever' => $minutes,
            'img_icon_url' => str_repeat('a', 40),
            'has_community_visible_stats' => true,
            'playtime_windows_forever' => $minutes,
            'playtime_mac_forever' => 0,
            'playtime_linux_forever' => 0,
            'playtime_deck_forever' => 0,
            'rtime_last_played' => $minutes > 0 ? 1_790_000_000 : 0,
            'playtime_disconnected' => 0,
            ...$overrides,
        ];
    }

    /**
     * Sign-in parameters Steam would send back to the callback.
     *
     * @return array<string, string>
     */
    protected function steamOpenIdResponse(string $steamId, string $returnTo): array
    {
        $claimed = "https://steamcommunity.com/openid/id/{$steamId}";

        return [
            'openid.ns' => 'http://specs.openid.net/auth/2.0',
            'openid.mode' => 'id_res',
            'openid.op_endpoint' => 'https://steamcommunity.com/openid/login',
            'openid.claimed_id' => $claimed,
            'openid.identity' => $claimed,
            'openid.return_to' => $returnTo,
            'openid.response_nonce' => '2026-11-20T12:00:00Zabc123',
            'openid.assoc_handle' => '1234567890',
            'openid.signed' => 'signed,op_endpoint,claimed_id,identity,return_to,response_nonce,assoc_handle',
            'openid.sig' => 'c2lnbmF0dXJl',
        ];
    }

    protected function connectSteam(User $user, string $steamId): SteamAccount
    {
        return SteamAccount::query()->create(['user_id' => $user->id, 'steam_id' => $steamId]);
    }

    private function steamResponse(Request $request): PromiseInterface
    {
        $url = $request->url();
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if ($host === 'steamcommunity.com' && $path === '/openid/login') {
            return Http::response("ns:http://specs.openid.net/auth/2.0\nis_valid:".($this->steamOpenIdValid ? 'true' : 'false')."\n");
        }

        if ($host === 'store.steampowered.com' && $path === '/api/appdetails') {
            $appId = (int) ($query['appids'] ?? 0);

            return Http::response([(string) $appId => isset($this->steamStore[$appId])
                ? ['success' => true, 'data' => $this->steamStore[$appId]]
                : ['success' => false]]);
        }

        if ($host !== 'api.steampowered.com') {
            return Http::response('Not found', 404);
        }

        if (str_contains($path, 'GetGlobalAchievementPercentagesForApp')) {
            $appId = (int) ($query['gameid'] ?? 0);

            return isset($this->steamPercentages[$appId])
                ? Http::response(['achievementpercentages' => ['achievements' => $this->steamPercentages[$appId]]])
                : Http::response(['achievementpercentages' => []], 403);
        }

        if (($query['key'] ?? null) !== self::STEAM_KEY) {
            return Http::response('<html>Forbidden</html>', 403);
        }

        if ($this->steamApiStatus !== null) {
            return Http::response('<html>Error</html>', $this->steamApiStatus);
        }

        $steamId = (string) ($query['steamid'] ?? $query['steamids'] ?? '');
        $appId = (int) ($query['appid'] ?? 0);

        return match (true) {
            str_contains($path, 'GetPlayerSummaries') => Http::response(['response' => [
                'players' => isset($this->steamPlayers[$steamId]) ? [$this->steamPlayers[$steamId]] : [],
            ]]),
            str_contains($path, 'GetBadges') => Http::response(['response' => $this->steamBadges[$steamId] ?? (object) []]),
            str_contains($path, 'GetOwnedGames') => Http::response(['response' => ($this->steamLibraries[$steamId] ?? null) === null
                ? (object) []
                : ['game_count' => count($this->steamLibraries[$steamId]), 'games' => $this->steamLibraries[$steamId]]]),
            str_contains($path, 'ResolveVanityURL') => Http::response(['response' => isset($this->steamVanity[(string) ($query['vanityurl'] ?? '')])
                ? ['steamid' => $this->steamVanity[(string) $query['vanityurl']], 'success' => 1]
                : ['success' => 42, 'message' => 'No match']]),
            str_contains($path, 'GetSchemaForGame') => Http::response(['game' => isset($this->steamSchemas[$appId])
                ? ['gameName' => "App {$appId}", 'availableGameStats' => ['achievements' => $this->steamSchemas[$appId]]]
                : (object) []]),
            str_contains($path, 'GetPlayerAchievements') => $this->steamPlayerAchievements($steamId, $appId),
            default => Http::response('Not found', 404),
        };
    }

    private function steamPlayerAchievements(string $steamId, int $appId): PromiseInterface
    {
        if (($this->steamPlayers[$steamId]['communityvisibilitystate'] ?? 3) !== 3) {
            return Http::response(['playerstats' => ['error' => 'Profile is not public', 'success' => false]], 403);
        }

        $unlocks = $this->steamUnlocks["{$steamId}:{$appId}"] ?? null;

        return $unlocks === null
            ? Http::response(['playerstats' => ['error' => 'Requested app has no stats', 'success' => false]], 400)
            : Http::response(['playerstats' => ['steamID' => $steamId, 'gameName' => "App {$appId}", 'achievements' => $unlocks, 'success' => true]]);
    }
}
