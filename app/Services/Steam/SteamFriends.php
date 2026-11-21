<?php

namespace App\Services\Steam;

use App\Models\SteamAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The friends of a Steam account with their public profile and what they
 * are playing, read from Steam when the page is opened and cached for a few
 * minutes. Nothing about them is stored in the database.
 *
 * @phpstan-type Friend array{steam_id: string, persona_name: string, avatar_url: string|null, profile_url: string, persona_state: int, playing: array{app_id: int|null, name: string}|null, friend_since: string|null, last_logoff_at: string|null, country_code: string|null}
 * @phpstan-type FriendList array{friends: list<Friend>, private: bool, error: string|null}
 */
class SteamFriends
{
    private const TTL = 300;

    public function __construct(private readonly SteamClient $steam)
    {
        //
    }

    /**
     * @return FriendList
     */
    public function of(SteamAccount $account): array
    {
        $key = "steam:friends:{$account->id}:{$account->steam_id}";
        $cached = Cache::get($key);

        if (is_array($cached)) {
            /** @var FriendList $cached */
            return $cached;
        }

        try {
            $list = $this->steam->friends($account->steam_id);
            $profiles = $list === null || $list === [] ? [] : $this->steam->playerSummaries(array_column($list, 'steam_id'));
        } catch (SteamException $exception) {
            return ['friends' => [], 'private' => false, 'error' => $exception->getMessage()];
        }

        $friends = [];

        foreach ($list ?? [] as $friend) {
            $profile = $profiles[$friend['steam_id']] ?? null;

            if ($profile !== null) {
                $friends[] = $this->present($friend['steam_id'], $profile, $friend['since']);
            }
        }

        $result = ['friends' => $friends, 'private' => $list === null, 'error' => null];
        Cache::put($key, $result, self::TTL);

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $profile
     * @return Friend
     */
    private function present(string $steamId, array $profile, ?int $since): array
    {
        $name = self::string($profile, 'personaname') ?? $steamId;
        $gameId = $profile['gameid'] ?? null;
        $playing = self::string($profile, 'gameextrainfo');
        $state = $profile['personastate'] ?? 0;
        $lastLogoff = $profile['lastlogoff'] ?? null;
        $country = self::string($profile, 'loccountrycode');

        return [
            'steam_id' => $steamId,
            'persona_name' => $name,
            'avatar_url' => self::url($profile['avatarmedium'] ?? $profile['avatarfull'] ?? null),
            'profile_url' => self::url($profile['profileurl'] ?? null) ?? "https://steamcommunity.com/profiles/{$steamId}",
            'persona_state' => is_int($state) ? max(0, min(6, $state)) : 0,
            'playing' => $playing === null ? null : [
                'app_id' => is_string($gameId) && ctype_digit($gameId) && strlen($gameId) <= 10 && (int) $gameId <= 4_294_967_295 ? (int) $gameId : null,
                'name' => $playing,
            ],
            'friend_since' => $since === null ? null : self::iso($since),
            'last_logoff_at' => is_int($lastLogoff) && $lastLogoff > 0 ? self::iso($lastLogoff) : null,
            'country_code' => $country !== null && preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null,
        ];
    }

    private static function iso(int $timestamp): string
    {
        return CarbonImmutable::createFromTimestamp($timestamp, (string) config('app.timezone'))->toIso8601String();
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 255) : null;
    }

    private static function url(mixed $value): ?string
    {
        return is_string($value) && preg_match('#^https?://[^\s"\'<>]+$#', $value) === 1
            ? (preg_replace('#^http://#', 'https://', $value) ?? $value)
            : null;
    }
}
