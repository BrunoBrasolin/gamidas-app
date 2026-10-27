<?php

namespace App\Models;

use App\Enums\WatchType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Date;

/**
 * Where a person of the household stopped an item they have not finished.
 * playing_since is set while Jellyfin reports it playing.
 *
 * @property int $id
 * @property int $household_id
 * @property string $viewer
 * @property string $jellyfin_item_id
 * @property WatchType $type
 * @property string $title
 * @property string|null $series_name
 * @property int|null $season_number
 * @property int|null $episode_number
 * @property int|null $year
 * @property int|null $runtime_seconds
 * @property array<string, string>|null $provider_ids
 * @property int $position_seconds
 * @property CarbonImmutable|null $playing_since
 * @property CarbonImmutable $last_played_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['household_id', 'viewer', 'jellyfin_item_id', 'type', 'title', 'series_name', 'season_number', 'episode_number', 'year', 'runtime_seconds', 'provider_ids', 'position_seconds', 'playing_since', 'last_played_at'])]
class WatchProgress extends Model
{
    protected $table = 'watch_progress';

    /**
     * When no runtime is known, a playback is assumed over after this long.
     */
    private const PLAYING_FALLBACK_SECONDS = 4 * 3600;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WatchType::class,
            'season_number' => 'integer',
            'episode_number' => 'integer',
            'year' => 'integer',
            'runtime_seconds' => 'integer',
            'provider_ids' => 'array',
            'position_seconds' => 'integer',
            'playing_since' => 'datetime',
            'last_played_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * Playing now, unless the stop event was lost: a playback older than the
     * item's runtime (plus a margin for pauses) is no longer considered on.
     */
    public function isPlaying(): bool
    {
        if ($this->playing_since === null) {
            return false;
        }

        $limit = ($this->runtime_seconds ?? self::PLAYING_FALLBACK_SECONDS) + 1800;

        return $this->playing_since->addSeconds($limit)->greaterThan(Date::now());
    }
}
