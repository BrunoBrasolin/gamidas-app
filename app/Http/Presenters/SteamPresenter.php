<?php

namespace App\Http\Presenters;

use App\Models\SteamAccount;
use App\Models\SteamAchievement;
use App\Models\SteamGame;
use App\Services\Steam\SteamClient;

/**
 * Shapes Steam records for the frontend (resources/js/types/games.ts).
 */
class SteamPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function account(SteamAccount $account, int $pendingAchievements): array
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
            'checked_at' => $account->checked_at?->toIso8601String(),
            'synced_at' => $account->synced_at?->toIso8601String(),
            'sync_error' => $account->sync_error,
            'pending_achievements' => $pendingAchievements,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function game(SteamGame $game): array
    {
        return [
            'id' => $game->id,
            'app_id' => $game->app_id,
            'name' => $game->name,
            'icon_url' => SteamClient::iconUrl($game->app_id, $game->icon_hash),
            'header_url' => SteamClient::headerUrl($game->app_id),
            'cover_url' => SteamClient::coverUrl($game->app_id),
            'playtime_minutes' => $game->playtime_minutes,
            'playtime_2weeks_minutes' => $game->playtime_2weeks_minutes,
            'last_played_at' => $game->last_played_at?->toIso8601String(),
            'achievements_total' => $game->achievements_total,
            'achievements_unlocked' => $game->achievements_unlocked,
        ];
    }

    /**
     * A game with everything its page shows.
     *
     * @return array<string, mixed>
     */
    public static function gameDetails(SteamGame $game): array
    {
        return [
            ...self::game($game),
            'store_url' => SteamClient::storeUrl($game->app_id),
            'platforms' => [
                'windows' => $game->playtime_windows_minutes,
                'mac' => $game->playtime_mac_minutes,
                'linux' => $game->playtime_linux_minutes,
                'deck' => $game->playtime_deck_minutes,
            ],
            'added_at' => $game->added_at->toIso8601String(),
            'removed_at' => $game->removed_at?->toIso8601String(),
            'achievements_synced_at' => $game->achievements_synced_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function achievement(SteamAchievement $achievement, bool $withGame = false): array
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
            'game' => $withGame ? [
                'id' => $achievement->game->id,
                'name' => $achievement->game->name,
            ] : null,
        ];
    }
}
