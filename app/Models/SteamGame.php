<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SteamGameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A game of a Steam account's library, with its playtime in minutes as
 * Steam reports it (total, last two weeks and per platform) and how many of
 * its achievements were unlocked. Achievement totals are null until the
 * game's achievements are synced.
 *
 * @property int $id
 * @property int $steam_account_id
 * @property int $app_id
 * @property string $name
 * @property string|null $icon_hash
 * @property int $playtime_minutes
 * @property int $playtime_2weeks_minutes
 * @property int $playtime_windows_minutes
 * @property int $playtime_mac_minutes
 * @property int $playtime_linux_minutes
 * @property int $playtime_deck_minutes
 * @property CarbonImmutable|null $last_played_at
 * @property CarbonImmutable $added_at When the game first appeared in a sync.
 * @property CarbonImmutable|null $removed_at When it left the library.
 * @property int|null $achievements_total
 * @property int|null $achievements_unlocked
 * @property CarbonImmutable|null $achievements_synced_at
 * @property int|null $achievements_playtime_minutes Playtime when the achievements were synced.
 * @property list<array{name: string, label: string|null, value: int|float}>|null $stats The game's own stats, null until fetched.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SteamAccount $account
 */
#[Fillable([
    'steam_account_id', 'app_id', 'name', 'icon_hash', 'playtime_minutes', 'playtime_2weeks_minutes',
    'playtime_windows_minutes', 'playtime_mac_minutes', 'playtime_linux_minutes', 'playtime_deck_minutes',
    'last_played_at', 'added_at', 'removed_at', 'achievements_total', 'achievements_unlocked',
    'achievements_synced_at', 'achievements_playtime_minutes', 'stats',
])]
class SteamGame extends Model
{
    /** @use HasFactory<SteamGameFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'app_id' => 'integer',
            'playtime_minutes' => 'integer',
            'playtime_2weeks_minutes' => 'integer',
            'playtime_windows_minutes' => 'integer',
            'playtime_mac_minutes' => 'integer',
            'playtime_linux_minutes' => 'integer',
            'playtime_deck_minutes' => 'integer',
            'last_played_at' => 'datetime',
            'added_at' => 'datetime',
            'removed_at' => 'datetime',
            'achievements_total' => 'integer',
            'achievements_unlocked' => 'integer',
            'achievements_synced_at' => 'datetime',
            'achievements_playtime_minutes' => 'integer',
            'stats' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SteamAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SteamAccount::class, 'steam_account_id');
    }

    /**
     * @return HasMany<SteamAchievement, $this>
     */
    public function achievements(): HasMany
    {
        return $this->hasMany(SteamAchievement::class);
    }

    /**
     * @return HasMany<SteamPlaytimeDay, $this>
     */
    public function playtimeDays(): HasMany
    {
        return $this->hasMany(SteamPlaytimeDay::class);
    }

    /**
     * Games still in the library.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInLibrary(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    /**
     * Whether the achievements and stats must be fetched again: never
     * fetched, or the game was played since.
     */
    public function achievementsStale(): bool
    {
        return $this->achievements_synced_at === null
            || $this->stats === null
            || $this->achievements_playtime_minutes !== $this->playtime_minutes;
    }
}
