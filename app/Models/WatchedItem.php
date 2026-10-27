<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\WatchType;
use Carbon\CarbonImmutable;
use Database\Factories\WatchedItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A movie, episode or video the user watched to the end, private to them.
 * watched_on is the local day of watched_at, used to count an item once a day.
 *
 * @property int $id
 * @property int $user_id
 * @property WatchType $type
 * @property string $title
 * @property string|null $series_name
 * @property int|null $season_number
 * @property int|null $episode_number
 * @property int|null $year
 * @property int|null $runtime_seconds
 * @property string|null $jellyfin_item_id
 * @property CarbonImmutable $watched_at
 * @property CarbonImmutable $watched_on
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'type', 'title', 'series_name', 'season_number', 'episode_number', 'year', 'runtime_seconds', 'jellyfin_item_id', 'watched_at', 'watched_on'])]
class WatchedItem extends Model
{
    /** @use HasFactory<WatchedItemFactory> */
    use HasFactory;

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
            'watched_at' => 'datetime',
            'watched_on' => DateOnly::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
