<?php

namespace App\Services\Steam;

use App\Models\SteamAccount;
use App\Models\SteamAchievement;
use App\Models\SteamGame;
use App\Models\SteamPlaytimeDay;
use App\Models\SteamWishlistItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Totals and history of a Steam library for its page: games played and
 * never played, playtime (total, last two weeks, per platform), achievement
 * completion, and the minutes played per month and day recorded by the
 * syncs.
 *
 * @phpstan-type Summary array{games: int, played: int, never_played: int, playtime_minutes: int, playtime_2weeks_minutes: int, recent_games: int, achievements_unlocked: int, achievements_total: int, perfect_games: int, average_completion_bp: int|null, platforms: array{windows: int, mac: int, linux: int, deck: int}}
 */
class SteamStats
{
    /**
     * @param  Collection<int, SteamGame>  $games  The games in the library.
     * @return Summary
     */
    public function summary(Collection $games): array
    {
        $played = $games->filter(fn (SteamGame $game) => $game->playtime_minutes > 0)->count();
        $withAchievements = $games->filter(fn (SteamGame $game) => $game->achievements_total > 0 && $game->achievements_unlocked !== null);
        // Like Steam's "average completion": over games with at least one achievement unlocked.
        $started = $withAchievements->filter(fn (SteamGame $game) => $game->achievements_unlocked > 0);

        return [
            'games' => $games->count(),
            'played' => $played,
            'never_played' => $games->count() - $played,
            'playtime_minutes' => (int) $games->sum('playtime_minutes'),
            'playtime_2weeks_minutes' => (int) $games->sum('playtime_2weeks_minutes'),
            'recent_games' => $games->filter(fn (SteamGame $game) => $game->playtime_2weeks_minutes > 0)->count(),
            'achievements_unlocked' => (int) $withAchievements->sum('achievements_unlocked'),
            'achievements_total' => (int) $withAchievements->sum('achievements_total'),
            'perfect_games' => $withAchievements->filter(fn (SteamGame $game) => $game->achievements_unlocked === $game->achievements_total)->count(),
            'average_completion_bp' => $started->isEmpty()
                ? null
                : (int) round($started->avg(fn (SteamGame $game) => intdiv((int) $game->achievements_unlocked * 10_000, (int) $game->achievements_total)) ?? 0),
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
     * The achievements unlocked most recently, with their game.
     *
     * @return Collection<int, SteamAchievement>
     */
    public function recentAchievements(SteamAccount $account, int $limit = 12): Collection
    {
        return SteamAchievement::query()
            ->whereIn('steam_game_id', $account->games()->select('id'))
            ->where('achieved', true)
            ->whereNotNull('unlocked_at')
            ->with('game')
            ->orderByDesc('unlocked_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<string, int> minutes by "YYYY-MM-DD"
     */
    private function minutesByDay(SteamAccount $account, CarbonImmutable $from, ?SteamGame $game = null): Collection
    {
        return SteamPlaytimeDay::query()
            ->where('steam_account_id', $account->id)
            ->when($game !== null, fn ($query) => $query->where('steam_game_id', $game?->id))
            ->where('played_on', '>=', $from->toDateString())
            ->get(['played_on', 'minutes'])
            ->groupBy(fn (SteamPlaytimeDay $day) => $day->played_on->toDateString())
            ->map(fn (Collection $rows) => (int) $rows->sum('minutes'));
    }
}
