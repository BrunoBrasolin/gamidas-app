<?php

namespace App\Models;

use App\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Minutes of a game played on a local day, measured as the growth of its
 * total playtime between two syncs.
 *
 * @property int $id
 * @property int $steam_account_id
 * @property int $steam_game_id
 * @property CarbonImmutable $played_on
 * @property int $minutes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SteamGame $game
 */
#[Fillable(['steam_account_id', 'steam_game_id', 'played_on', 'minutes'])]
class SteamPlaytimeDay extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['played_on' => DateOnly::class, 'minutes' => 'integer'];
    }

    /**
     * @return BelongsTo<SteamGame, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(SteamGame::class, 'steam_game_id');
    }
}
