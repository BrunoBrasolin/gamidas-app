<?php

namespace App\Services\Steam;

use App\Models\SteamAccount;
use App\Models\SteamAchievement;
use App\Models\SteamGame;
use App\Models\SteamPlaytimeDay;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Connects a user's Steam account and keeps it in sync: the profile (name,
 * avatar, status, level, XP and bans), the library with playtime, the
 * minutes played per game and day, the wishlist with store prices, and the
 * achievements and stats of each game.
 *
 * Steam only reports each game's total playtime, so the daily history is
 * the growth of that total between two syncs, credited to the day the game
 * was last played (or the sync day), never more than the time elapsed
 * between the syncs. The first sync only sets the starting point.
 */
class SteamSyncService
{
    /**
     * Games whose achievements a scheduled run fetches, per account.
     */
    public const ACHIEVEMENT_GAMES_PER_RUN = 40;

    /**
     * Seconds the game's achievement schema stays cached (it rarely changes).
     */
    private const SCHEMA_TTL = 7 * 24 * 3600;

    /**
     * Seconds the global unlock percentages stay cached.
     */
    private const PERCENTAGES_TTL = 24 * 3600;

    private const MAX_APP_ID = 4_294_967_295;

    /**
     * Stats kept per game (some games track hundreds of internal counters).
     */
    private const MAX_STATS = 300;

    public function __construct(private readonly SteamClient $steam)
    {
        //
    }

    /**
     * Link the Steam account to the user, replacing a different account
     * they had (and everything synced from it). A Steam account belongs to
     * a single user.
     *
     * @throws SteamException when another user already connected it
     */
    public function connect(User $user, string $steamId): SteamAccount
    {
        if (SteamAccount::query()->where('steam_id', $steamId)->where('user_id', '!=', $user->id)->exists()) {
            throw new SteamException('Esta conta Steam já está conectada por outra pessoa.', 409);
        }

        return DB::transaction(function () use ($user, $steamId) {
            SteamAccount::query()->where('user_id', $user->id)->where('steam_id', '!=', $steamId)->delete();

            return SteamAccount::query()->firstOrCreate(['user_id' => $user->id], ['steam_id' => $steamId]);
        });
    }

    /**
     * Refresh the profile, level and library. When the game details are
     * private, the profile is updated, the library is kept as it was and
     * the reason is recorded in sync_error.
     *
     * @throws SteamException also recorded in sync_error
     */
    public function sync(SteamAccount $account): void
    {
        $now = Date::now();

        try {
            $player = $this->steam->playerSummary($account->steam_id)
                ?? throw new SteamException('A Steam não encontrou esta conta.', 404);
            $badges = $this->steam->badges($account->steam_id);
            $bans = $this->steam->playerBans($account->steam_id);
            $games = $this->steam->ownedGames($account->steam_id);
            // A private profile hides the wishlist too: keep the one we have.
            $wishlist = $games === null ? null : $this->steam->wishlist($account->steam_id);
            $store = $wishlist === null || $wishlist === [] ? [] : $this->steam->storeItems(array_column($wishlist, 'app_id'));
        } catch (SteamException $exception) {
            $account->forceFill(['checked_at' => $now, 'sync_error' => mb_substr($exception->getMessage(), 0, 255)])->save();

            throw $exception;
        }

        DB::transaction(function () use ($account, $player, $badges, $bans, $games, $wishlist, $store, $now) {
            $account->fill([...$this->profile($player, $badges), ...$this->bans($bans)]);

            if ($games !== null) {
                $this->syncLibrary($account, $games, $now);
            }

            if ($wishlist !== null) {
                $this->syncWishlist($account, $wishlist, $store);
            }

            $account->forceFill([
                'public_games' => $games !== null,
                'checked_at' => $now,
                'synced_at' => $games !== null ? $now : $account->synced_at,
                'sync_error' => $games === null ? SteamException::privateProfile()->getMessage() : null,
            ])->save();
        });
    }

    /**
     * Fetch the achievements and stats of up to $limit games played since
     * their last fetch (or never fetched), most recently played first. A
     * game Steam fails to answer for is skipped (and tried again next time);
     * failures that would hit every game stop the run.
     *
     * @return int how many games were fetched
     *
     * @throws SteamException
     */
    public function syncAchievements(SteamAccount $account, int $limit = self::ACHIEVEMENT_GAMES_PER_RUN): int
    {
        $games = $this->pendingQuery($account)
            ->orderByDesc('last_played_at')
            ->orderByDesc('playtime_minutes')
            ->limit($limit)
            ->get();
        $synced = 0;

        foreach ($games as $game) {
            try {
                $this->syncGameAchievements($account, $game);
                $synced++;
            } catch (SteamException $exception) {
                if ($exception->status === null || in_array($exception->status, [401, 403, 429], true)) {
                    throw $exception;
                }
            }
        }

        return $synced;
    }

    /**
     * Games played whose achievements and stats were never fetched, or were
     * fetched before the game was played again.
     *
     * @return HasMany<SteamGame, SteamAccount>
     */
    private function pendingQuery(SteamAccount $account): HasMany
    {
        return $account->games()
            ->inLibrary()
            ->where('playtime_minutes', '>', 0)
            ->where(fn ($query) => $query
                ->whereNull('achievements_synced_at')
                ->orWhereNull('stats')
                ->orWhereColumn('achievements_playtime_minutes', '<>', 'playtime_minutes'));
    }

    /**
     * Fetch one game's achievements and stats: the schema (names,
     * descriptions, icons), what the player unlocked and when, how rare each
     * achievement is, and the player's value of each stat the game tracks.
     *
     * @throws SteamException
     */
    public function syncGameAchievements(SteamAccount $account, SteamGame $game): void
    {
        $gameSchema = $this->schema($game->app_id);
        $schema = $gameSchema['achievements'];
        $player = $schema === [] ? [] : ($this->steam->playerAchievements($account->steam_id, $game->app_id) ?? []);
        $percentages = $schema === [] ? [] : $this->percentages($game->app_id);
        $stats = $gameSchema['stats'] === [] ? [] : $this->stats($gameSchema['stats'], $this->steam->playerStats($account->steam_id, $game->app_id) ?? []);
        $now = Date::now();

        DB::transaction(function () use ($game, $schema, $player, $percentages, $stats, $now) {
            $existing = $game->achievements()->get()->keyBy('api_name');
            $new = [];
            $kept = [];
            $unlocked = 0;

            foreach ($schema as $entry) {
                $apiName = self::string($entry, 'name', 128);

                if ($apiName === null || isset($kept[$apiName])) {
                    continue;
                }

                $state = $player[$apiName] ?? ['achieved' => false, 'unlocked_at' => null];
                $attributes = [
                    'name' => self::string($entry, 'displayName') ?? $apiName,
                    'description' => self::string($entry, 'description', 2_000),
                    'icon_url' => self::url($entry['icon'] ?? null),
                    'icon_gray_url' => self::url($entry['icongray'] ?? null),
                    'hidden' => (int) ($entry['hidden'] ?? 0) === 1,
                    'global_percent_bp' => $percentages[$apiName] ?? null,
                    'achieved' => $state['achieved'],
                    'unlocked_at' => $state['achieved'] ? self::time($state['unlocked_at']) : null,
                    'position' => count($kept),
                ];
                $kept[$apiName] = true;
                $unlocked += $state['achieved'] ? 1 : 0;

                $achievement = $existing->get($apiName);

                if ($achievement === null) {
                    $new[] = [...$attributes, 'steam_game_id' => $game->id, 'api_name' => $apiName, 'created_at' => $now, 'updated_at' => $now];
                } else {
                    $achievement->fill($attributes)->save();
                }
            }

            foreach (array_chunk($new, 200) as $chunk) {
                SteamAchievement::query()->insert(array_map(fn (array $row) => [
                    ...$row,
                    'hidden' => $row['hidden'] ? 1 : 0,
                    'achieved' => $row['achieved'] ? 1 : 0,
                ], $chunk));
            }

            $gone = $existing->keys()->diff(array_keys($kept));

            if ($gone->isNotEmpty()) {
                $game->achievements()->whereIn('api_name', $gone->all())->delete();
            }

            $game->forceFill([
                'achievements_total' => count($kept),
                'achievements_unlocked' => $unlocked,
                'achievements_synced_at' => $now,
                'achievements_playtime_minutes' => $game->playtime_minutes,
                'stats' => $stats,
            ])->save();
        });
    }

    /**
     * @param  list<array<array-key, mixed>>  $games
     */
    private function syncLibrary(SteamAccount $account, array $games, CarbonImmutable $now): void
    {
        $previous = $account->synced_at;
        $existing = $account->games()->get()->keyBy('app_id');
        $seen = [];

        foreach ($games as $data) {
            $appId = self::int($data, 'appid');

            if ($appId === null || $appId <= 0 || $appId > self::MAX_APP_ID || isset($seen[$appId])) {
                continue;
            }

            $seen[$appId] = true;
            $playtime = self::minutes($data, 'playtime_forever');
            $lastPlayed = self::time(self::int($data, 'rtime_last_played'));
            $attributes = [
                'name' => self::string($data, 'name') ?? "App {$appId}",
                'icon_hash' => self::string($data, 'img_icon_url', 64),
                'playtime_minutes' => $playtime,
                'playtime_2weeks_minutes' => self::minutes($data, 'playtime_2weeks'),
                'playtime_windows_minutes' => self::minutes($data, 'playtime_windows_forever'),
                'playtime_mac_minutes' => self::minutes($data, 'playtime_mac_forever'),
                'playtime_linux_minutes' => self::minutes($data, 'playtime_linux_forever'),
                'playtime_deck_minutes' => self::minutes($data, 'playtime_deck_forever'),
                'last_played_at' => $lastPlayed,
                'removed_at' => null,
            ];

            $game = $existing->get($appId);
            $before = $game === null ? 0 : $game->playtime_minutes;

            if ($game === null) {
                $game = $account->games()->create([...$attributes, 'app_id' => $appId, 'added_at' => $now]);
            } else {
                $game->fill($attributes)->save();
            }

            if ($previous !== null && $playtime > $before) {
                $this->recordPlaytime($account, $game, $playtime - $before, $previous, $lastPlayed, $now);
            }
        }

        $removed = $existing
            ->filter(fn (SteamGame $game) => $game->removed_at === null && ! isset($seen[$game->app_id]))
            ->pluck('id');

        foreach ($removed->chunk(200) as $ids) {
            SteamGame::query()->whereIn('id', $ids->all())->update(['removed_at' => $now, 'playtime_2weeks_minutes' => 0]);
        }
    }

    /**
     * Credit minutes played between two syncs to the day the game was last
     * played, when that falls between them, or else to the sync day.
     */
    private function recordPlaytime(SteamAccount $account, SteamGame $game, int $minutes, CarbonImmutable $previous, ?CarbonImmutable $lastPlayed, CarbonImmutable $now): void
    {
        $minutes = min($minutes, (int) ceil($previous->diffInMinutes($now, true)) + 5);

        if ($minutes <= 0) {
            return;
        }

        $day = $lastPlayed !== null && $lastPlayed->greaterThan($previous) && $lastPlayed->lessThanOrEqualTo($now)
            ? $lastPlayed
            : $now;

        $row = SteamPlaytimeDay::query()->firstOrNew(
            ['steam_game_id' => $game->id, 'played_on' => $day->toDateString()],
            ['steam_account_id' => $account->id, 'minutes' => 0],
        );
        $row->minutes += $minutes;
        $row->save();
    }

    /**
     * @param  array<array-key, mixed>  $bans
     * @return array<string, mixed>
     */
    private function bans(array $bans): array
    {
        if ($bans === []) {
            return [];
        }

        $economy = self::string($bans, 'EconomyBan', 32);

        return [
            'vac_bans' => min(65_535, self::int($bans, 'NumberOfVACBans') ?? 0),
            'game_bans' => min(65_535, self::int($bans, 'NumberOfGameBans') ?? 0),
            'days_since_last_ban' => self::int($bans, 'DaysSinceLastBan'),
            'community_banned' => ($bans['CommunityBanned'] ?? false) === true,
            'trade_banned' => $economy !== null && $economy !== 'none',
        ];
    }

    /**
     * Replace the stored wishlist with the one Steam sent, with each game's
     * store data.
     *
     * @param  list<array{app_id: int, priority: int, added_at: int|null}>  $wishlist
     * @param  array<int, array<array-key, mixed>>  $store
     */
    private function syncWishlist(SteamAccount $account, array $wishlist, array $store): void
    {
        $existing = $account->wishlistItems()->get()->keyBy('app_id');
        $seen = [];

        foreach ($wishlist as $item) {
            if (isset($seen[$item['app_id']]) || $item['app_id'] > self::MAX_APP_ID) {
                continue;
            }

            $seen[$item['app_id']] = true;
            $row = $existing->get($item['app_id']);
            $attributes = [
                ...$this->storeItem($item['app_id'], $store[$item['app_id']] ?? []),
                'priority' => $item['priority'],
                'added_at' => self::time($item['added_at']),
            ];
            $attributes['name'] ??= $row->name ?? "App {$item['app_id']}";

            if ($row === null) {
                $account->wishlistItems()->create([...$attributes, 'app_id' => $item['app_id']]);
            } else {
                $row->fill($attributes)->save();
            }
        }

        $gone = $existing->keys()->diff(array_keys($seen));

        foreach ($gone->chunk(200) as $appIds) {
            $account->wishlistItems()->whereIn('app_id', $appIds->all())->delete();
        }
    }

    /**
     * What the store shows for a wishlisted game. Without store data the
     * name stays null (the caller keeps the last known one).
     *
     * @param  array<array-key, mixed>  $item
     * @return array<string, mixed>
     */
    private function storeItem(int $appId, array $item): array
    {
        $price = is_array($item['best_purchase_option'] ?? null) ? $item['best_purchase_option'] : [];
        $release = is_array($item['release'] ?? null) ? $item['release'] : [];
        $reviews = is_array($item['reviews']['summary_filtered'] ?? null) ? $item['reviews']['summary_filtered'] : [];
        $assets = is_array($item['assets'] ?? null) ? $item['assets'] : [];
        $format = self::string($assets, 'asset_url_format');
        $header = self::string($assets, 'header');
        $releaseAt = self::time(self::int($release, 'steam_release_date') ?? self::int($release, 'original_steam_release_date'));
        $final = self::int($price, 'final_price_in_cents');
        $original = self::int($price, 'original_price_in_cents');
        $discount = self::int($price, 'discount_pct') ?? 0;
        $percent = self::int($reviews, 'percent_positive');

        return [
            'name' => self::string($item, 'name'),
            'price_cents' => $final,
            'original_price_cents' => $original !== null && $final !== null && $original > $final ? $original : null,
            'discount_percent' => max(0, min(100, $discount)),
            'price_formatted' => self::string($price, 'formatted_final_price', 32),
            'original_price_formatted' => self::string($price, 'formatted_original_price', 32),
            'is_free' => ($item['is_free'] ?? false) === true,
            'release_at' => $releaseAt,
            'coming_soon' => ($release['is_coming_soon'] ?? false) === true || ($releaseAt !== null && $releaseAt->isFuture()),
            'review_label' => self::string($reviews, 'review_score_label', 64),
            'review_percent' => $percent === null ? null : min(100, $percent),
            'review_count' => self::int($reviews, 'review_count'),
            'header_url' => ($format !== null && $header !== null ? SteamClient::assetUrl(str_replace('${FILENAME}', $header, $format)) : null)
                ?? SteamClient::headerUrl($appId),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $player
     * @param  array<array-key, mixed>  $badges
     * @return array<string, mixed>
     */
    private function profile(array $player, array $badges): array
    {
        $country = self::string($player, 'loccountrycode', 2);
        $gameId = $player['gameid'] ?? null;
        $playing = is_string($gameId) && ctype_digit($gameId) && (int) $gameId <= self::MAX_APP_ID ? (int) $gameId : null;

        return [
            'persona_name' => self::string($player, 'personaname'),
            'profile_url' => self::url($player['profileurl'] ?? null),
            'avatar_url' => self::url($player['avatarfull'] ?? $player['avatarmedium'] ?? null),
            'country_code' => $country !== null && preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null,
            'public_profile' => self::int($player, 'communityvisibilitystate') === 3,
            'persona_state' => max(0, min(6, self::int($player, 'personastate') ?? 0)),
            'playing_app_id' => $playing,
            'playing_name' => self::string($player, 'gameextrainfo'),
            'account_created_at' => self::time(self::int($player, 'timecreated')),
            'last_logoff_at' => self::time(self::int($player, 'lastlogoff')),
            'level' => self::int($badges, 'player_level'),
            'xp' => self::int($badges, 'player_xp'),
            'xp_level_start' => self::int($badges, 'player_xp_needed_current_level'),
            'xp_to_next_level' => self::int($badges, 'player_xp_needed_to_level_up'),
            'badges_count' => is_array($badges['badges'] ?? null) ? count($badges['badges']) : null,
        ];
    }

    /**
     * The game's achievement and stat schema, cached for a week once fetched.
     *
     * @return array{achievements: list<array<array-key, mixed>>, stats: list<array<array-key, mixed>>}
     *
     * @throws SteamException
     */
    private function schema(int $appId): array
    {
        $key = "steam:schema:v2:{$appId}:".config('services.steam.language');
        $cached = Cache::get($key);

        if (is_array($cached) && is_array($cached['achievements'] ?? null) && is_array($cached['stats'] ?? null)) {
            /** @var array{achievements: list<array<array-key, mixed>>, stats: list<array<array-key, mixed>>} $cached */
            return $cached;
        }

        $schema = $this->steam->gameSchema($appId);
        Cache::put($key, $schema, self::SCHEMA_TTL);

        return $schema;
    }

    /**
     * The player's stats in the schema's order, with the game's name for
     * each (or null when the game gives none); stats the schema doesn't
     * list come last.
     *
     * @param  list<array<array-key, mixed>>  $schema
     * @param  array<string, int|float>  $values
     * @return list<array{name: string, label: string|null, value: int|float}>
     */
    private function stats(array $schema, array $values): array
    {
        $stats = [];

        foreach ($schema as $entry) {
            $name = self::string($entry, 'name', 128);

            if ($name !== null && array_key_exists($name, $values)) {
                $stats[$name] = ['name' => $name, 'label' => self::string($entry, 'displayName'), 'value' => $values[$name]];
            }
        }

        foreach ($values as $name => $value) {
            $stats[$name] ??= ['name' => mb_substr($name, 0, 128), 'label' => null, 'value' => $value];
        }

        return array_slice(array_values($stats), 0, self::MAX_STATS);
    }

    /**
     * Global unlock percentages, cached for a day; a failure is not cached.
     *
     * @return array<string, int>
     */
    private function percentages(int $appId): array
    {
        $key = "steam:percentages:{$appId}";
        $cached = Cache::get($key);

        if (is_array($cached)) {
            /** @var array<string, int> $cached */
            return $cached;
        }

        $percentages = $this->steam->globalPercentages($appId);

        if ($percentages !== []) {
            Cache::put($key, $percentages, self::PERCENTAGES_TTL);
        }

        return $percentages;
    }

    private static function time(?int $timestamp): ?CarbonImmutable
    {
        return $timestamp !== null && $timestamp > 0
            ? CarbonImmutable::createFromTimestamp($timestamp, (string) config('app.timezone'))
            : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function minutes(array $data, string $key): int
    {
        return max(0, self::int($data, $key) ?? 0);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return match (true) {
            is_int($value) => $value,
            is_string($value) && ctype_digit($value) && strlen($value) <= 18 => (int) $value,
            default => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function string(array $data, string $key, int $max = 255): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }

    /**
     * An https URL that fits its column (Steam still sends some as http).
     */
    private static function url(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('#^https?://[^\s]+$#', $value) !== 1) {
            return null;
        }

        $url = preg_replace('#^http://#', 'https://', $value) ?? $value;

        return strlen($url) <= 255 ? $url : null;
    }
}
