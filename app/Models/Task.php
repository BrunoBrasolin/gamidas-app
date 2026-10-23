<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\TaskGoalType;
use App\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A personal task tracked by its owner. Its status is derived from the
 * completion and archival timestamps, and its time progress comes only from
 * manually recorded time entries, never from timer sessions.
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string|null $description
 * @property TaskGoalType $goal_type
 * @property int|null $target_minutes
 * @property string|null $outcome
 * @property CarbonImmutable|null $deadline
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'title', 'description', 'goal_type', 'target_minutes', 'outcome', 'deadline', 'completed_at', 'archived_at'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'goal_type' => TaskGoalType::class,
            'target_minutes' => 'integer',
            'deadline' => DateOnly::class,
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
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
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * @return HasMany<TimerSession, $this>
     */
    public function timerSessions(): HasMany
    {
        return $this->hasMany(TimerSession::class);
    }

    public function status(): TaskStatus
    {
        return match (true) {
            $this->archived_at !== null => TaskStatus::Archived,
            $this->completed_at !== null => TaskStatus::Completed,
            default => TaskStatus::Active,
        };
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithStatus(Builder $query, TaskStatus $status): Builder
    {
        return match ($status) {
            TaskStatus::Archived => $query->whereNotNull('archived_at'),
            TaskStatus::Completed => $query->whereNull('archived_at')->whereNotNull('completed_at'),
            TaskStatus::Active => $query->whereNull('archived_at')->whereNull('completed_at'),
        };
    }
}
