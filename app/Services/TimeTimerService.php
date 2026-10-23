<?php

namespace App\Services;

use App\Models\TimeCategory;
use App\Models\TimeLog;
use App\Models\TimeTimer;
use App\Support\Day;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The per-category stopwatch. The server owns the clock: the browser only
 * renders elapsed time, so a timer keeps counting across reloads and devices.
 */
class TimeTimerService
{
    public function __construct(private readonly TimeLogService $logs)
    {
        //
    }

    /**
     * Start (or resume) the category's timer. Starting a running timer is a no-op.
     */
    public function start(TimeCategory $category): TimeTimer
    {
        if ($category->isArchived()) {
            throw ValidationException::withMessages([
                'category' => 'Categoria arquivada não pode ter cronômetro em andamento.',
            ]);
        }

        return DB::transaction(function () use ($category) {
            $timer = $this->locked($category) ?? new TimeTimer([
                'user_id' => $category->user_id,
                'time_category_id' => $category->id,
                'accumulated_seconds' => 0,
            ]);

            if (! $timer->isRunning()) {
                $timer->started_at = Date::now();
                $timer->save();
            }

            return $timer;
        });
    }

    /**
     * Stop counting but keep the elapsed time. Pausing a stopped timer is a no-op.
     */
    public function pause(TimeCategory $category): void
    {
        DB::transaction(function () use ($category) {
            $timer = $this->locked($category);

            if ($timer?->isRunning()) {
                $timer->forceFill([
                    'accumulated_seconds' => $timer->elapsedSeconds(),
                    'started_at' => null,
                ])->save();
            }
        });
    }

    public function reset(TimeCategory $category): void
    {
        $category->timer()->delete();
    }

    /**
     * Add the elapsed time to today's cell of the sheet and clear the timer.
     * Returns the seconds that were added.
     */
    public function commit(TimeCategory $category): int
    {
        return DB::transaction(function () use ($category) {
            $timer = $this->locked($category);
            $elapsed = $timer?->elapsedSeconds() ?? 0;

            if ($timer === null || $elapsed === 0) {
                $timer?->delete();

                return 0;
            }

            $today = Day::today();
            $current = (int) TimeLog::query()
                ->where('time_category_id', $category->id)
                ->where('log_date', $today->toDateString())
                ->value('seconds');

            $this->logs->save($category, $today, min(TimeLog::MAX_SECONDS, $current + $elapsed), null, false);
            $timer->delete();

            return $elapsed;
        });
    }

    private function locked(TimeCategory $category): ?TimeTimer
    {
        return TimeTimer::query()->where('time_category_id', $category->id)->lockForUpdate()->first();
    }
}
