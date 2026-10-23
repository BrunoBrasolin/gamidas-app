<?php

namespace App\Models;

use App\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Database\Factories\TimeEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Time recorded manually by the user for a task on a local calendar date. The
 * daily time sheet and every time report are built from these entries only.
 *
 * @property int $id
 * @property int $user_id
 * @property int $task_id
 * @property CarbonImmutable $entry_date
 * @property int $duration_minutes
 * @property string|null $description
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Task $task
 */
#[Fillable(['user_id', 'task_id', 'entry_date', 'duration_minutes', 'description'])]
class TimeEntry extends Model
{
    /** @use HasFactory<TimeEntryFactory> */
    use HasFactory;

    /**
     * Longest single entry: a whole day.
     */
    public const MAX_MINUTES = 1440;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_date' => DateOnly::class,
            'duration_minutes' => 'integer',
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
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPage(): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'date' => $this->entry_date->toDateString(),
            'minutes' => $this->duration_minutes,
            'description' => $this->description,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
