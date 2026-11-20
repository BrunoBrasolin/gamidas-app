<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An achievement of a game, as the game's schema describes it, whether the
 * player unlocked it and how rare it is among all Steam players.
 *
 * @property int $id
 * @property int $steam_game_id
 * @property string $api_name
 * @property string $name
 * @property string|null $description
 * @property string|null $icon_url
 * @property string|null $icon_gray_url
 * @property bool $hidden
 * @property int|null $global_percent_bp Hundredths of a percent (1234 = 12,34%).
 * @property bool $achieved
 * @property CarbonImmutable|null $unlocked_at
 * @property int $position Order in the game's schema.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SteamGame $game
 */
#[Fillable([
    'steam_game_id', 'api_name', 'name', 'description', 'icon_url', 'icon_gray_url', 'hidden',
    'global_percent_bp', 'achieved', 'unlocked_at', 'position',
])]
class SteamAchievement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hidden' => 'boolean',
            'global_percent_bp' => 'integer',
            'achieved' => 'boolean',
            'unlocked_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SteamGame, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(SteamGame::class, 'steam_game_id');
    }
}
