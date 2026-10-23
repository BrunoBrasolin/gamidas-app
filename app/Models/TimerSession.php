<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\TimerStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Date;

/**
 * One measurement of time spent on a task with the timer. The elapsed time is
 * the accumulated time of finished running stretches plus, while running, the
 * time since the last resume — always computed from server timestamps.
 *
 * Timer sessions are measurements only: they never create or change manual
 * time entries.
 *
 * @property int $id
 * @property int $user_id
 * @property int $task_id
 * @property TimerStatus $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $last_resumed_at
 * @property CarbonImmutable|null $paused_at
 * @property CarbonImmutable|null $ended_at
 * @property int $accumulated_seconds
 * @property int|null $running_lock
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Task $task
 */
#[Fillable(['user_id', 'task_id', 'status', 'started_at', 'last_resumed_at', 'paused_at', 'ended_at', 'accumulated_seconds', 'running_lock'])]
class TimerSession extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TimerStatus::class,
            'started_at' => UtcDateTime::class,
            'last_resumed_at' => UtcDateTime::class,
            'paused_at' => UtcDateTime::class,
            'ended_at' => UtcDateTime::class,
            'accumulated_seconds' => 'integer',
            'running_lock' => 'integer',
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

    public function isRunning(): bool
    {
        return $this->status === TimerStatus::Running;
    }

    public function isOpen(): bool
    {
        return $this->status !== TimerStatus::Stopped;
    }

    /**
     * Seconds of the stretch currently running, never negative (a clock
     * going backwards or a missing resume timestamp count as zero).
     */
    public function runningSeconds(?CarbonInterface $now = null): int
    {
        if (! $this->isRunning() || $this->last_resumed_at === null) {
            return 0;
        }

        return max(0, (int) $this->last_resumed_at->diffInSeconds($now ?? Date::now(), false));
    }

    public function elapsedSeconds(?CarbonInterface $now = null): int
    {
        return max(0, $this->accumulated_seconds) + $this->runningSeconds($now);
    }

    /**
     * Sessions that are running or paused.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [TimerStatus::Running, TimerStatus::Paused]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPage(?CarbonInterface $now = null): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'task' => [
                'id' => $this->task->id,
                'title' => $this->task->title,
                'archived' => $this->task->isArchived(),
            ],
            'started_at' => $this->started_at->toIso8601String(),
            'started_on' => $this->started_at->toDateString(),
            'paused_at' => $this->paused_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'elapsed_seconds' => $this->elapsedSeconds($now),
        ];
    }
}
