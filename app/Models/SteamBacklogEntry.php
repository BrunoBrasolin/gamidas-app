<?php

namespace App\Models;

use App\Enums\BacklogStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A game on the user's "para zerar" list: waiting its turn, being played or
 * finished, with the Steam playtime when it entered the list and when it
 * was finished.
 *
 * @property int $id
 * @property int $user_id
 * @property int $app_id
 * @property string $name The game's name when it entered the list.
 * @property BacklogStatus $status
 * @property int $position Order in the queue, from 0.
 * @property int $playtime_at_add Minutes.
 * @property int|null $playtime_at_completion Minutes.
 * @property CarbonImmutable $added_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'user_id', 'app_id', 'name', 'status', 'position', 'playtime_at_add', 'playtime_at_completion',
    'added_at', 'started_at', 'completed_at',
])]
class SteamBacklogEntry extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'app_id' => 'integer',
            'status' => BacklogStatus::class,
            'position' => 'integer',
            'playtime_at_add' => 'integer',
            'playtime_at_completion' => 'integer',
            'added_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
