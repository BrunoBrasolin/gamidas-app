<?php

namespace App\Http\Presenters;

use App\Models\SteamAccount;
use App\Models\SteamAchievement;
use App\Models\SteamBacklogEntry;
use App\Models\SteamGame;
use App\Models\SteamWishlistItem;
use App\Services\Steam\SteamClient;
use App\Services\Steam\SteamListsService;
use App\Services\Steam\SteamStats;
use Carbon\CarbonImmutable;

/**
 * Shapes Steam records for the frontend (resources/js/types/games.ts).
 *
 * @phpstan-import-type LibraryRow from SteamStats
 * @phpstan-import-type ListGame from SteamListsService
 */
class SteamPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function account(SteamAccount $account): array
    {
        return [
            'steam_id' => $account->steam_id,
            'persona_name' => $account->persona_name,
            'profile_url' => $account->profile_url ?? "https://steamcommunity.com/profiles/{$account->steam_id}",
            'avatar_url' => $account->avatar_url,
            'country_code' => $account->country_code,
            'public_profile' => $account->public_profile,
            'public_games' => $account->public_games,
            'persona_state' => $account->persona_state,
            'playing' => $account->playing_name === null ? null : [
                'app_id' => $account->playing_app_id,
                'name' => $account->playing_name,
            ],
            'account_created_at' => $account->account_created_at?->toIso8601String(),
            'last_logoff_at' => $account->last_logoff_at?->toIso8601String(),
            'level' => $account->level,
            'xp' => $account->xp,
            'xp_level_start' => $account->xp_level_start,
            'xp_to_next_level' => $account->xp_to_next_level,
            'badges_count' => $account->badges_count,
            'bans' => $account->vac_bans === null ? null : [
                'vac' => $account->vac_bans,
                'game' => (int) $account->game_bans,
                'days_since_last' => $account->days_since_last_ban,
                'community' => (bool) $account->community_banned,
                'trade' => (bool) $account->trade_banned,
            ],
            'checked_at' => $account->checked_at?->toIso8601String(),
            'synced_at' => $account->synced_at?->toIso8601String(),
            'sync_error' => $account->sync_error,
        ];
    }

    /**
     * A game of the library list. Images are built from the app id on the
     * page, which keeps a list of thousands of games small.
     *
     * @param  LibraryRow  $row
     * @return array{id: int, app_id: int, name: string, playtime_minutes: int, playtime_2weeks_minutes: int, last_played_at: string|null}
     */
    public static function libraryGame(array $row): array
    {
        return [
            'id' => $row['id'],
            'app_id' => $row['app_id'],
            'name' => $row['name'],
            'playtime_minutes' => $row['playtime_minutes'],
            'playtime_2weeks_minutes' => $row['playtime_2weeks_minutes'],
            'last_played_at' => $row['last_played_at'] === null
                ? null
                : CarbonImmutable::parse($row['last_played_at'], (string) config('app.timezone'))->toIso8601String(),
        ];
    }

    /**
     * A game with what its page shows at once (achievements and stats come
     * later, from Steam if needed).
     *
     * @return array<string, mixed>
     */
    public static function gameDetails(SteamGame $game): array
    {
        return [
            'id' => $game->id,
            'app_id' => $game->app_id,
            'name' => $game->name,
            'playtime_minutes' => $game->playtime_minutes,
            'playtime_2weeks_minutes' => $game->playtime_2weeks_minutes,
            'last_played_at' => $game->last_played_at?->toIso8601String(),
            'store_url' => SteamClient::storeUrl($game->app_id),
            'platforms' => [
                'windows' => $game->playtime_windows_minutes,
                'mac' => $game->playtime_mac_minutes,
                'linux' => $game->playtime_linux_minutes,
                'deck' => $game->playtime_deck_minutes,
            ],
            'removed_at' => $game->removed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function wishlistItem(SteamWishlistItem $item, ?int $ownedGameId): array
    {
        return [
            'id' => $item->id,
            'app_id' => $item->app_id,
            'name' => $item->name,
            'priority' => $item->priority,
            'added_at' => $item->added_at?->toIso8601String(),
            'price_cents' => $item->price_cents,
            'original_price_cents' => $item->original_price_cents,
            'discount_percent' => $item->discount_percent,
            'price_formatted' => $item->price_formatted,
            'original_price_formatted' => $item->original_price_formatted,
            'is_free' => $item->is_free,
            'release_at' => $item->release_at?->toIso8601String(),
            'coming_soon' => $item->coming_soon,
            'review_label' => $item->review_label,
            'review_percent' => $item->review_percent,
            'review_count' => $item->review_count,
            'header_url' => $item->header_url ?? SteamClient::headerUrl($item->app_id),
            'store_url' => SteamClient::storeUrl($item->app_id),
            'owned_game_id' => $ownedGameId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function achievement(SteamAchievement $achievement): array
    {
        return [
            'id' => $achievement->id,
            'name' => $achievement->name,
            'description' => $achievement->description,
            'icon_url' => $achievement->achieved ? $achievement->icon_url : ($achievement->icon_gray_url ?? $achievement->icon_url),
            'hidden' => $achievement->hidden,
            'global_percent_bp' => $achievement->global_percent_bp,
            'achieved' => $achievement->achieved,
            'unlocked_at' => $achievement->unlocked_at?->toIso8601String(),
        ];
    }

    /**
     * @param  ListGame  $game
     * @return array<string, mixed>
     */
    public static function backlogEntry(SteamBacklogEntry $entry, array $game, ?string $tier): array
    {
        return [
            ...$game,
            'id' => $entry->id,
            'status' => $entry->status->value,
            'position' => $entry->position,
            'playtime_at_add' => $entry->playtime_at_add,
            'playtime_at_completion' => $entry->playtime_at_completion,
            'added_at' => $entry->added_at->toIso8601String(),
            'started_at' => $entry->started_at?->toIso8601String(),
            'completed_at' => $entry->completed_at?->toIso8601String(),
            'tier' => $tier,
        ];
    }
}
