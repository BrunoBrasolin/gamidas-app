<?php

namespace App\Models;

use App\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Database\Factories\TimeLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The sheet cell of one category on one local calendar date: the time worked
 * (in seconds) and a Markdown note, either of which may be empty.
 *
 * @property int $id
 * @property int $user_id
 * @property int $time_category_id
 * @property CarbonImmutable $log_date
 * @property int $seconds
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'time_category_id', 'log_date', 'seconds', 'note'])]
class TimeLog extends Model
{
    /** @use HasFactory<TimeLogFactory> */
    use HasFactory;

    /**
     * Longest time in one cell: a whole day.
     */
    public const MAX_SECONDS = 86_400;

    /**
     * Longest note, in characters.
     */
    public const MAX_NOTE_LENGTH = 200_000;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'log_date' => DateOnly::class,
            'seconds' => 'integer',
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
     * @return BelongsTo<TimeCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TimeCategory::class, 'time_category_id');
    }
}
