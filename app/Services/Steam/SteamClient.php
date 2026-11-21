<?php

namespace App\Services\Steam;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A client for the Steam Web API (api.steampowered.com), authenticated with
 * the server's Web API key, and for the public store API. Web API failures
 * throw {@see SteamException}; store failures answer null, so the game page
 * shows what was synced instead of breaking. The key travels in the query
 * string, so URLs and client errors are never logged.
 *
 * @phpstan-type PlayerAchievement array{achieved: bool, unlocked_at: int|null}
 */
class SteamClient
{
    public const API_URL = 'https://api.steampowered.com/';

    public const STORE_API_URL = 'https://store.steampowered.com/api/';

    private const ICON_URL = 'https://media.steampowered.com/steamcommunity/public/images/apps/';

    private const ASSET_URL_ROOT = 'https://shared.cloudflare.steamstatic.com/store_item_assets/';

    private const ASSET_URL = self::ASSET_URL_ROOT.'steam/apps/';

    public function configured(): bool
    {
        return filled(config('services.steam.key'));
    }

    /**
     * The profile: name, avatar, visibility, status and what is being played;
     * null when no account has this SteamID.
     *
     * @return array<array-key, mixed>|null
     *
     * @throws SteamException
     */
    public function playerSummary(string $steamId): ?array
    {
        return $this->playerSummaries([$steamId])[$steamId] ?? null;
    }

    /**
     * Profiles by SteamID64, 100 per request; unknown ids are left out.
     *
     * @param  list<string>  $steamIds
     * @return array<string, array<array-key, mixed>>
     *
     * @throws SteamException
     */
    public function playerSummaries(array $steamIds): array
    {
        $players = [];

        foreach (array_chunk(array_values(array_unique($steamIds)), 100) as $chunk) {
            $json = $this->get('ISteamUser/GetPlayerSummaries/v2/', ['steamids' => implode(',', $chunk)]);

            foreach (is_array($json['response']['players'] ?? null) ? $json['response']['players'] : [] as $player) {
                if (is_array($player) && is_string($player['steamid'] ?? null)) {
                    $players[$player['steamid']] = $player;
                }
            }
        }

        return $players;
    }

    /**
     * VAC, game, community and trade bans of the account.
     *
     * @return array<array-key, mixed>
     *
     * @throws SteamException
     */
    public function playerBans(string $steamId): array
    {
        $json = $this->get('ISteamUser/GetPlayerBans/v1/', ['steamids' => $steamId]);
        $player = $json['players'][0] ?? null;

        return is_array($player) ? $player : [];
    }

    /**
     * The account's friends (SteamID64 and since when); null when the
     * friends list is private.
     *
     * @return list<array{steam_id: string, since: int|null}>|null
     *
     * @throws SteamException
     */
    public function friends(string $steamId): ?array
    {
        $response = $this->request('ISteamUser/GetFriendList/v1/', ['steamid' => $steamId, 'relationship' => 'friend']);

        if (in_array($response->status(), [401, 403], true)) {
            return null;
        }

        $json = $this->decode('ISteamUser/GetFriendList', $response);
        $friends = [];

        foreach (is_array($json['friendslist']['friends'] ?? null) ? $json['friendslist']['friends'] : [] as $friend) {
            if (is_array($friend) && is_string($friend['steamid'] ?? null)) {
                $since = $friend['friend_since'] ?? null;
                $friends[] = ['steam_id' => $friend['steamid'], 'since' => is_int($since) && $since > 0 ? $since : null];
            }
        }

        return $friends;
    }

    /**
     * The wishlist: app id, the user's rank (0 when unranked) and when each
     * game was added. Empty when the wishlist is private or empty.
     *
     * @return list<array{app_id: int, priority: int, added_at: int|null}>
     *
     * @throws SteamException
     */
    public function wishlist(string $steamId): array
    {
        $json = $this->get('IWishlistService/GetWishlist/v1/', ['steamid' => $steamId]);
        $items = [];

        foreach (is_array($json['response']['items'] ?? null) ? $json['response']['items'] : [] as $item) {
            $appId = is_array($item) ? ($item['appid'] ?? null) : null;

            if (is_int($appId) && $appId > 0) {
                $added = $item['date_added'] ?? null;
                $items[] = [
                    'app_id' => $appId,
                    'priority' => is_int($item['priority'] ?? null) ? max(0, $item['priority']) : 0,
                    'added_at' => is_int($added) && $added > 0 ? $added : null,
                ];
            }
        }

        return $items;
    }

    /**
     * Store data of several apps (name, price, discount, release, reviews,
     * images), by app id, in the configured language and country; apps the
     * store does not show are left out.
     *
     * @param  list<int>  $appIds
     * @return array<int, array<array-key, mixed>>
     *
     * @throws SteamException
     */
    public function storeItems(array $appIds): array
    {
        $items = [];

        foreach (array_chunk(array_values(array_unique($appIds)), 50) as $chunk) {
            $json = $this->get('IStoreBrowseService/GetItems/v1/', ['input_json' => (string) json_encode([
                'ids' => array_map(fn (int $appId) => ['appid' => $appId], $chunk),
                'context' => ['language' => $this->language(), 'country_code' => strtoupper((string) config('services.steam.country'))],
                'data_request' => ['include_assets' => true, 'include_release' => true, 'include_reviews' => true],
            ])]);

            foreach (is_array($json['response']['store_items'] ?? null) ? $json['response']['store_items'] : [] as $item) {
                if (is_array($item) && ($item['success'] ?? null) === 1 && is_int($item['appid'] ?? null)) {
                    $items[$item['appid']] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * Level, XP and badges; empty when the profile hides them.
     *
     * @return array<array-key, mixed>
     *
     * @throws SteamException
     */
    public function badges(string $steamId): array
    {
        $json = $this->get('IPlayerService/GetBadges/v1/', ['steamid' => $steamId]);

        return is_array($json['response'] ?? null) ? $json['response'] : [];
    }

    /**
     * Every game of the library, free games played included, with playtime
     * in minutes; null when the profile's game details are private (Steam
     * then answers an empty response instead of a game count).
     *
     * @return list<array<array-key, mixed>>|null
     *
     * @throws SteamException
     */
    public function ownedGames(string $steamId): ?array
    {
        $json = $this->get('IPlayerService/GetOwnedGames/v1/', [
            'steamid' => $steamId,
            'include_appinfo' => 1,
            'include_played_free_games' => 1,
            'skip_unvetted_apps' => 0,
            'language' => $this->language(),
        ]);
        $response = $json['response'] ?? null;

        if (! is_array($response) || ! array_key_exists('game_count', $response)) {
            return null;
        }

        $games = is_array($response['games'] ?? null) ? $response['games'] : [];

        return array_values(array_filter($games, fn (mixed $game) => is_array($game)));
    }

    /**
     * The SteamID64 of a custom profile URL ("steamcommunity.com/id/<name>"),
     * null when no profile uses it.
     *
     * @throws SteamException
     */
    public function resolveVanityUrl(string $name): ?string
    {
        $json = $this->get('ISteamUser/ResolveVanityURL/v1/', ['vanityurl' => $name]);
        $response = is_array($json['response'] ?? null) ? $json['response'] : [];
        $steamId = $response['steamid'] ?? null;

        return ($response['success'] ?? null) === 1 && is_string($steamId) ? $steamId : null;
    }

    /**
     * The game's achievements (api name, display name, description, icons,
     * hidden) and stats (api name, display name), in the schema's order;
     * empty lists when the game has none.
     *
     * @return array{achievements: list<array<array-key, mixed>>, stats: list<array<array-key, mixed>>}
     *
     * @throws SteamException
     */
    public function gameSchema(int $appId): array
    {
        $response = $this->request('ISteamUserStats/GetSchemaForGame/v2/', ['appid' => $appId, 'l' => $this->language()]);

        // Apps without stats (or delisted) answer 400/403/404 instead of an empty schema.
        if (in_array($response->status(), [400, 403, 404], true)) {
            return ['achievements' => [], 'stats' => []];
        }

        $json = $this->decode('ISteamUserStats/GetSchemaForGame', $response);
        $stats = $json['game']['availableGameStats'] ?? [];
        $list = fn (mixed $entries) => is_array($entries) ? array_values(array_filter($entries, fn (mixed $entry) => is_array($entry))) : [];

        return [
            'achievements' => $list(is_array($stats) ? ($stats['achievements'] ?? null) : null),
            'stats' => $list(is_array($stats) ? ($stats['stats'] ?? null) : null),
        ];
    }

    /**
     * The player's own stats in a game (whatever the game tracks: kills,
     * distance, wins…), by api name; null when the game keeps none for
     * them or Steam can't give them.
     *
     * @return array<string, int|float>|null
     *
     * @throws SteamException a private profile throws {@see SteamException::privateProfile()}
     */
    public function playerStats(string $steamId, int $appId): ?array
    {
        $response = $this->request('ISteamUserStats/GetUserStatsForGame/v2/', ['steamid' => $steamId, 'appid' => $appId]);

        if ($response->status() === 403) {
            throw SteamException::privateProfile();
        }

        $stats = $response->successful() ? $response->json('playerstats.stats') : null;

        if (! is_array($stats)) {
            return null;
        }

        $values = [];

        foreach ($stats as $stat) {
            $value = is_array($stat) ? ($stat['value'] ?? null) : null;

            if (is_array($stat) && is_string($stat['name'] ?? null) && (is_int($value) || is_float($value))) {
                $values[$stat['name']] = $value;
            }
        }

        return $values;
    }

    /**
     * What the player unlocked in a game, by api name; null when the game
     * has no stats.
     *
     * @return array<string, PlayerAchievement>|null
     *
     * @throws SteamException a private profile throws {@see SteamException::privateProfile()}
     */
    public function playerAchievements(string $steamId, int $appId): ?array
    {
        $response = $this->request('ISteamUserStats/GetPlayerAchievements/v1/', ['steamid' => $steamId, 'appid' => $appId]);
        $stats = $response->json('playerstats');
        $error = is_array($stats) && is_string($stats['error'] ?? null) ? $stats['error'] : '';

        if ($response->status() === 403 || str_contains(strtolower($error), 'not public')) {
            throw SteamException::privateProfile();
        }

        if ($response->status() === 400 || (is_array($stats) && ($stats['success'] ?? null) === false)) {
            return null;
        }

        $this->decode('ISteamUserStats/GetPlayerAchievements', $response);
        $unlocked = [];

        foreach (is_array($stats['achievements'] ?? null) ? $stats['achievements'] : [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['apiname'] ?? null)) {
                continue;
            }

            $time = $entry['unlocktime'] ?? null;
            $unlocked[$entry['apiname']] = [
                'achieved' => (int) ($entry['achieved'] ?? 0) === 1,
                'unlocked_at' => is_int($time) && $time > 0 ? $time : null,
            ];
        }

        return $unlocked;
    }

    /**
     * The share of all Steam players who unlocked each achievement, by api
     * name, in hundredths of a percent. Needs no key; failures answer [].
     *
     * @return array<string, int>
     */
    public function globalPercentages(int $appId): array
    {
        try {
            $response = Http::acceptJson()->timeout(10)
                ->get(self::API_URL.'ISteamUserStats/GetGlobalAchievementPercentagesForApp/v2/', ['gameid' => $appId]);
        } catch (ConnectionException) {
            return [];
        }

        $achievements = $response->successful() ? $response->json('achievementpercentages.achievements') : null;
        $percentages = [];

        foreach (is_array($achievements) ? $achievements : [] as $entry) {
            $percent = is_array($entry) ? ($entry['percent'] ?? null) : null;

            if (is_array($entry) && is_string($entry['name'] ?? null) && is_numeric($percent)) {
                $percentages[$entry['name']] = (int) max(0, min(10_000, round((float) $percent * 100)));
            }
        }

        return $percentages;
    }

    /**
     * The game's store page data (description, genres, developers, price,
     * screenshots…), in the configured language and country; null when the
     * store does not answer or does not know the app.
     *
     * @return array<array-key, mixed>|null
     */
    public function appDetails(int $appId): ?array
    {
        try {
            $response = Http::acceptJson()->timeout(8)->get(self::STORE_API_URL.'appdetails', [
                'appids' => $appId,
                'l' => $this->language(),
                'cc' => (string) config('services.steam.country'),
            ]);
        } catch (ConnectionException) {
            Log::warning('Steam store unreachable', ['app_id' => $appId]);

            return null;
        }

        $app = $response->successful() ? $response->json((string) $appId) : null;

        if (! is_array($app) || ($app['success'] ?? false) !== true || ! is_array($app['data'] ?? null)) {
            return null;
        }

        return $app['data'];
    }

    public static function iconUrl(int $appId, ?string $hash): ?string
    {
        return $hash !== null && preg_match('/^[0-9a-f]{40}$/', $hash) === 1 ? self::ICON_URL."{$appId}/{$hash}.jpg" : null;
    }

    /**
     * The 460×215 store header.
     */
    public static function headerUrl(int $appId): string
    {
        return self::ASSET_URL."{$appId}/header.jpg";
    }

    /**
     * The 600×900 library cover (not every game has one).
     */
    public static function coverUrl(int $appId): string
    {
        return self::ASSET_URL."{$appId}/library_600x900.jpg";
    }

    /**
     * A store asset from its path in the store data ("steam/apps/<id>/…").
     */
    public static function assetUrl(string $path): ?string
    {
        return preg_match('#^steam/apps/\d+/[A-Za-z0-9_./-]+(\?t=\d+)?$#', $path) === 1 ? self::ASSET_URL_ROOT.$path : null;
    }

    public static function storeUrl(int $appId): string
    {
        return "https://store.steampowered.com/app/{$appId}/";
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<array-key, mixed>
     *
     * @throws SteamException
     */
    private function get(string $path, array $query): array
    {
        return $this->decode($path, $this->request($path, $query));
    }

    /**
     * @param  array<string, string|int>  $query
     *
     * @throws SteamException when Steam can't be reached
     */
    private function request(string $path, array $query): Response
    {
        if (! $this->configured()) {
            throw SteamException::notConfigured();
        }

        try {
            return Http::acceptJson()
                ->timeout(15)
                ->get(self::API_URL.$path, ['key' => (string) config('services.steam.key'), ...$query]);
        } catch (ConnectionException) {
            Log::warning('Steam unreachable', ['path' => $path]);

            throw new SteamException('Não foi possível falar com a Steam agora. Tente de novo em alguns minutos.');
        }
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws SteamException
     */
    private function decode(string $path, Response $response): array
    {
        if (! $response->successful()) {
            Log::warning('Steam request failed', ['path' => $path, 'status' => $response->status()]);

            throw new SteamException(match (true) {
                in_array($response->status(), [401, 403], true) => 'A Steam recusou a chave da API (STEAM_API_KEY). Confira a chave no .env do servidor.',
                $response->status() === 429 => 'A Steam limitou as consultas por enquanto. Tente de novo em alguns minutos.',
                default => 'A Steam não respondeu direito agora (erro '.$response->status().'). Tente de novo em alguns minutos.',
            }, $response->status());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new SteamException('A Steam mandou uma resposta inesperada.', $response->status());
        }

        return $json;
    }

    private function language(): string
    {
        return (string) config('services.steam.language');
    }
}
