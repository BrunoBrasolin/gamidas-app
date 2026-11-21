<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SteamAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's Steam account: the profile as the last sync saw it. Private to
 * the user who connected it, like its games, achievements and playtime.
 *
 * @property int $id
 * @property int $user_id
 * @property string $steam_id SteamID64
 * @property string|null $persona_name
 * @property string|null $profile_url
 * @property string|null $avatar_url
 * @property string|null $country_code
 * @property bool $public_profile
 * @property bool $public_games
 * @property int $persona_state 0 offline, 1 online, 2 busy, 3 away, 4 snooze, 5 looking to trade, 6 looking to play
 * @property int|null $playing_app_id
 * @property string|null $playing_name
 * @property CarbonImmutable|null $account_created_at
 * @property CarbonImmutable|null $last_logoff_at
 * @property int|null $level
 * @property int|null $xp
 * @property int|null $xp_level_start
 * @property int|null $xp_to_next_level
 * @property int|null $badges_count
 * @property int|null $vac_bans
 * @property int|null $game_bans
 * @property int|null $days_since_last_ban
 * @property bool|null $community_banned
 * @property bool|null $trade_banned
 * @property CarbonImmutable|null $checked_at Last sync attempt.
 * @property CarbonImmutable|null $synced_at Last time the library's playtime was read.
 * @property string|null $sync_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id', 'steam_id', 'persona_name', 'profile_url', 'avatar_url', 'country_code', 'public_profile',
    'public_games', 'persona_state', 'playing_app_id', 'playing_name', 'account_created_at', 'last_logoff_at',
    'level', 'xp', 'xp_level_start', 'xp_to_next_level', 'badges_count', 'vac_bans', 'game_bans', 'days_since_last_ban',
    'community_banned', 'trade_banned', 'checked_at', 'synced_at', 'sync_error',
])]
class SteamAccount extends Model
{
    /** @use HasFactory<SteamAccountFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'public_profile' => 'boolean',
            'public_games' => 'boolean',
            'persona_state' => 'integer',
            'playing_app_id' => 'integer',
            'account_created_at' => 'datetime',
            'last_logoff_at' => 'datetime',
            'level' => 'integer',
            'xp' => 'integer',
            'xp_level_start' => 'integer',
            'xp_to_next_level' => 'integer',
            'badges_count' => 'integer',
            'vac_bans' => 'integer',
            'game_bans' => 'integer',
            'days_since_last_ban' => 'integer',
            'community_banned' => 'boolean',
            'trade_banned' => 'boolean',
            'checked_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Every game the account ever had, including the ones that left the
     * library (refunded or removed), which keep their playtime history.
     *
     * @return HasMany<SteamGame, $this>
     */
    public function games(): HasMany
    {
        return $this->hasMany(SteamGame::class);
    }

    /**
     * @return HasMany<SteamWishlistItem, $this>
     */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(SteamWishlistItem::class);
    }

    /**
     * @return HasMany<SteamPlaytimeDay, $this>
     */
    public function playtimeDays(): HasMany
    {
        return $this->hasMany(SteamPlaytimeDay::class);
    }
}
