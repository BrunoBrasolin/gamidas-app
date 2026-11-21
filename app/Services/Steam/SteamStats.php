<?php

namespace App\Services\Steam;

use App\Models\SteamAccount;
use App\Models\SteamGame;
use App\Models\SteamPlaytimeDay;
use App\Models\SteamWishlistItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Totals and history of a Steam library for its page: games played and
 * never played, playtime (total, last two weeks, per platform), achievements
 * unlocked, the minutes played per month and day recorded by the syncs, and
 * the wishlist's totals.
 *
 * @phpstan-type LibraryRow array{id: int, app_id: int, name: string, playtime_minutes: int, playtime_2weeks_minutes: int, playtime_windows_minutes: int, playtime_mac_minutes: int, playtime_linux_minutes: int, playtime_deck_minutes: int, last_played_at: string|null, achievements_total: int|null, achievements_unlocked: int|null}
 * @phpstan-type Summary array{games: int, played: int, never_played: int, playtime_minutes: int, playtime_2weeks_minutes: int, recent_games: int, achievements_unlocked: int, achievements_total: int, perfect_games: int, platforms: array{windows: int, mac: int, linux: int, deck: int}}
 */
class SteamStats
{
    /**
     * The games in the library, most played first, read as plain rows
     * (no Eloquent models): a library can have thousands of games.
     *
     * @return Collection<int, LibraryRow>
     */
    public function library(SteamAccount $account): Collection
    {
        $int = fn (mixed $value) => is_numeric($value) ? (int) $value : null;

        return $account->games()->inLibrary()->toBase()
            ->orderByDesc('playtime_minutes')
            ->orderBy('name')
            ->get([
                'id', 'app_id', 'name', 'playtime_minutes', 'playtime_2weeks_minutes', 'playtime_windows_minutes',
                'playtime_mac_minutes', 'playtime_linux_minutes', 'playtime_deck_minutes', 'last_played_at',
                'achievements_total', 'achievements_unlocked',
            ])
            ->map(function (object $row) use ($int) {
                $row = (array) $row;

                return [
                    'id' => (int) $int($row['id']),
                    'app_id' => (int) $int($row['app_id']),
                    'name' => (string) $row['name'],
                    'playtime_minutes' => (int) $int($row['playtime_minutes']),
                    'playtime_2weeks_minutes' => (int) $int($row['playtime_2weeks_minutes']),
                    'playtime_windows_minutes' => (int) $int($row['playtime_windows_minutes']),
                    'playtime_mac_minutes' => (int) $int($row['playtime_mac_minutes']),
                    'playtime_linux_minutes' => (int) $int($row['playtime_linux_minutes']),
                    'playtime_deck_minutes' => (int) $int($row['playtime_deck_minutes']),
                    'last_played_at' => is_string($row['last_played_at']) ? $row['last_played_at'] : null,
                    'achievements_total' => $int($row['achievements_total']),
                    'achievements_unlocked' => $int($row['achievements_unlocked']),
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, LibraryRow>  $games
     * @return Summary
     */
    public function summary(Collection $games): array
    {
        $played = $games->filter(fn (array $game) => $game['playtime_minutes'] > 0)->count();
        $withAchievements = $games->filter(fn (array $game) => $game['achievements_total'] > 0 && $game['achievements_unlocked'] !== null);

        return [
            'games' => $games->count(),
            'played' => $played,
            'never_played' => $games->count() - $played,
            'playtime_minutes' => (int) $games->sum('playtime_minutes'),
            'playtime_2weeks_minutes' => (int) $games->sum('playtime_2weeks_minutes'),
            'recent_games' => $games->filter(fn (array $game) => $game['playtime_2weeks_minutes'] > 0)->count(),
            'achievements_unlocked' => (int) $withAchievements->sum('achievements_unlocked'),
            'achievements_total' => (int) $withAchievements->sum('achievements_total'),
            'perfect_games' => $withAchievements->filter(fn (array $game) => $game['achievements_unlocked'] === $game['achievements_total'])->count(),
            'platforms' => [
                'windows' => (int) $games->sum('playtime_windows_minutes'),
                'mac' => (int) $games->sum('playtime_mac_minutes'),
                'linux' => (int) $games->sum('playtime_linux_minutes'),
                'deck' => (int) $games->sum('playtime_deck_minutes'),
            ],
        ];
    }

    /**
     * Totals of the wishlist. Prices are summed only when they are in reais
     * (the store country is Brazil); otherwise the totals are null.
     *
     * @param  Collection<int, SteamWishlistItem>  $items
     * @return array{items: int, on_sale: int, coming_soon: int, free: int, total_cents: int|null, savings_cents: int|null}
     */
    public function wishlistSummary(Collection $items): array
    {
        $brl = strtolower((string) config('services.steam.country')) === 'br';
        $priced = $items->filter(fn (SteamWishlistItem $item) => ! $item->is_free && $item->price_cents !== null);

        return [
            'items' => $items->count(),
            'on_sale' => $items->filter(fn (SteamWishlistItem $item) => $item->discount_percent > 0)->count(),
            'coming_soon' => $items->filter(fn (SteamWishlistItem $item) => $item->coming_soon)->count(),
            'free' => $items->filter(fn (SteamWishlistItem $item) => $item->is_free)->count(),
            'total_cents' => $brl ? (int) $priced->sum('price_cents') : null,
            'savings_cents' => $brl
                ? (int) $priced->sum(fn (SteamWishlistItem $item) => max(0, ($item->original_price_cents ?? $item->price_cents) - $item->price_cents))
                : null,
        ];
    }

    /**
     * Minutes played in each of the last $count months, oldest first, as
     * "YYYY-MM"; with a game, only that game.
     *
     * @return list<array{month: string, minutes: int}>
     */
    public function months(SteamAccount $account, int $count = 12, ?SteamGame $game = null): array
    {
        $first = Date::today()->startOfMonth()->subMonthsNoOverflow($count - 1);
        $totals = $this->minutesByDay($account, $first, $game)
            ->groupBy(fn (int $minutes, string $day) => substr($day, 0, 7))
            ->map(fn (Collection $days) => (int) $days->sum());
        $months = [];

        for ($month = $first; count($months) < $count; $month = $month->addMonthNoOverflow()) {
            $key = $month->format('Y-m');
            $months[] = ['month' => $key, 'minutes' => $totals->get($key, 0)];
        }

        return $months;
    }

    /**
     * Minutes played in each of the last $count days, oldest first.
     *
     * @return list<array{date: string, minutes: int}>
     */
    public function days(SteamAccount $account, int $count = 30): array
    {
        $first = Date::today()->subDays($count - 1);
        $totals = $this->minutesByDay($account, $first);
        $days = [];

        for ($day = $first; count($days) < $count; $day = $day->addDay()) {
            $days[] = ['date' => $day->toDateString(), 'minutes' => $totals->get($day->toDateString(), 0)];
        }

        return $days;
    }

    /**
     * The first day with recorded playtime, if any.
     */
    public function historySince(SteamAccount $account): ?string
    {
        $first = $account->playtimeDays()->min('played_on');

        return is_string($first) ? substr($first, 0, 10) : null;
    }

    /**
     * @return Collection<string, int> minutes by "YYYY-MM-DD"
     */
    private function minutesByDay(SteamAccount $account, CarbonImmutable $from, ?SteamGame $game = null): Collection
    {
        return SteamPlaytimeDay::query()
            ->toBase()
            ->where('steam_account_id', $account->id)
            ->when($game !== null, fn ($query) => $query->where('steam_game_id', $game?->id))
            ->where('played_on', '>=', $from->toDateString())
            ->get(['played_on', 'minutes'])
            ->map(fn (object $day) => (array) $day)
            ->groupBy(fn (array $day) => substr((string) $day['played_on'], 0, 10))
            ->map(fn (Collection $rows) => (int) $rows->sum(fn (array $day) => (int) $day['minutes']));
    }
}
