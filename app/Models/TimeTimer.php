<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Date;

/**
 * The stopwatch of a category: seconds banked by earlier runs plus, while it
 * is running, the time since started_at. A category has at most one.
 *
 * @property int $id
 * @property int $user_id
 * @property int $time_category_id
 * @property int $accumulated_seconds
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'time_category_id', 'accumulated_seconds', 'started_at'])]
class TimeTimer extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['accumulated_seconds' => 'integer', 'started_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<TimeCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TimeCategory::class, 'time_category_id');
    }

    public function isRunning(): bool
    {
        return $this->started_at !== null;
    }

    public function elapsedSeconds(): int
    {
        $running = $this->started_at === null
            ? 0
            : max(0, (int) $this->started_at->diffInSeconds(Date::now(), false));

        return $this->accumulated_seconds + $running;
    }
}
