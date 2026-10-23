<?php

namespace App\Services;

use App\Enums\TimerStatus;
use App\Models\Task;
use App\Models\TimerSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starts, pauses, resumes and stops task timers. A user has at most one
 * running session (enforced by the running_lock unique index); any number of
 * sessions may be paused. Every action is idempotent: repeating it (double
 * clicks, two tabs) leaves the session as it is.
 *
 * Timer sessions are measurements only. Nothing here creates, changes or
 * deletes manual time entries.
 */
class TimerService
{
    /**
     * What to do with a session that is already running when another one is
     * started or resumed.
     */
    public const KEEP = null;

    public const PAUSE_EXISTING = 'pause';

    public const STOP_EXISTING = 'stop';

    /**
     * The session shown as "the timer": the running one or, when none runs,
     * the most recently paused.
     */
    public function current(User $user): ?TimerSession
    {
        return TimerSession::query()
            ->with('task')
            ->where('user_id', $user->id)
            ->open()
            ->orderByRaw('case when status = ? then 0 else 1 end', [TimerStatus::Running->value])
            ->orderByDesc('paused_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Running and paused sessions of the user.
     *
     * @return Collection<int, TimerSession>
     */
    public function open(User $user): Collection
    {
        return TimerSession::query()
            ->with('task')
            ->where('user_id', $user->id)
            ->open()
            ->orderByRaw('case when status = ? then 0 else 1 end', [TimerStatus::Running->value])
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Start timing a task. Starting the task that is already running returns
     * that session; starting a task with a paused session resumes it. While
     * another task runs, the caller must decide to pause or stop it.
     */
    public function start(User $user, Task $task, ?string $existing = self::KEEP): TimerSession
    {
        if ($task->isArchived()) {
            throw ValidationException::withMessages(['task_id' => 'Tarefas arquivadas não podem ser cronometradas.']);
        }

        return $this->guardRace(fn () => DB::transaction(function () use ($user, $task, $existing) {
            $open = $this->lockOpen($user);
            $running = $open->first(fn (TimerSession $session) => $session->isRunning());

            if ($running?->task_id === $task->id) {
                return $running;
            }

            $this->settleRunning($running, $existing);

            $paused = $open->first(fn (TimerSession $session) => $session->task_id === $task->id && ! $session->isRunning());

            if ($paused !== null) {
                $this->markRunning($paused);

                return $paused;
            }

            $now = Date::now();

            return $user->timerSessions()->create([
                'task_id' => $task->id,
                'status' => TimerStatus::Running,
                'started_at' => $now,
                'last_resumed_at' => $now,
                'accumulated_seconds' => 0,
                'running_lock' => 1,
            ]);
        }));
    }

    /**
     * Pause a running session, banking the time of the current stretch.
     * Returns false when the session was not running.
     */
    public function pause(TimerSession $session): bool
    {
        return DB::transaction(function () use ($session) {
            $session = $this->lock($session);

            if (! $session->isRunning()) {
                return false;
            }

            $now = Date::now();
            $session->update([
                'status' => TimerStatus::Paused,
                'accumulated_seconds' => $session->elapsedSeconds($now),
                'last_resumed_at' => null,
                'paused_at' => $now,
                'running_lock' => null,
            ]);

            return true;
        });
    }

    /**
     * Resume a paused session. Returns false when it was already running.
     */
    public function resume(TimerSession $session, ?string $existing = self::KEEP): bool
    {
        return $this->guardRace(fn () => DB::transaction(function () use ($session, $existing) {
            $session = $this->lock($session);

            if ($session->isRunning()) {
                return false;
            }

            if ($session->status === TimerStatus::Stopped) {
                throw ValidationException::withMessages(['timer' => 'Esta sessão já foi encerrada. Inicie um novo cronômetro.']);
            }

            if ($session->task->isArchived()) {
                throw ValidationException::withMessages(['timer' => 'A tarefa foi arquivada; pare esta sessão.']);
            }

            $running = $this->lockOpen($session->user)->first(fn (TimerSession $open) => $open->isRunning());
            $this->settleRunning($running, $existing);
            $this->markRunning($session);

            return true;
        }));
    }

    /**
     * Stop a session, keeping it in the history. Returns false when it was
     * already stopped. Never records anything in the time sheet.
     */
    public function stop(TimerSession $session): bool
    {
        return DB::transaction(function () use ($session) {
            $session = $this->lock($session);

            if ($session->status === TimerStatus::Stopped) {
                return false;
            }

            $now = Date::now();
            $session->update([
                'status' => TimerStatus::Stopped,
                'accumulated_seconds' => $session->elapsedSeconds($now),
                'last_resumed_at' => null,
                'ended_at' => $now,
                'running_lock' => null,
            ]);

            return true;
        });
    }

    /**
     * Stop every open session of a task (used when it is archived).
     */
    public function stopForTask(Task $task): int
    {
        $sessions = $task->timerSessions()->open()->get();

        $sessions->each(fn (TimerSession $session) => $this->stop($session));

        return $sessions->count();
    }

    /**
     * Seconds measured by sessions started in a period (timer analytics,
     * always reported apart from recorded time).
     */
    public function measuredSeconds(User $user, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) TimerSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('started_at', [
                $from->utc()->format('Y-m-d H:i:s'),
                $to->utc()->format('Y-m-d H:i:s'),
            ])
            ->get()
            ->sum(fn (TimerSession $session) => $session->elapsedSeconds());
    }

    /**
     * @return Collection<int, TimerSession>
     */
    private function lockOpen(User $user): Collection
    {
        return TimerSession::query()
            ->where('user_id', $user->id)
            ->open()
            ->lockForUpdate()
            ->get();
    }

    private function lock(TimerSession $session): TimerSession
    {
        return TimerSession::query()->with(['task', 'user'])->lockForUpdate()->findOrFail($session->id);
    }

    /**
     * Pause or stop the running session as the caller decided, or refuse to
     * run two timers at once.
     */
    private function settleRunning(?TimerSession $running, ?string $existing): void
    {
        if ($running === null) {
            return;
        }

        match ($existing) {
            self::PAUSE_EXISTING => $this->pause($running),
            self::STOP_EXISTING => $this->stop($running),
            default => throw ValidationException::withMessages([
                'timer_conflict' => "O cronômetro de “{$running->task->title}” está em andamento. Pause ou pare antes de iniciar outro.",
            ]),
        };
    }

    private function markRunning(TimerSession $session): void
    {
        $session->update([
            'status' => TimerStatus::Running,
            'last_resumed_at' => Date::now(),
            'paused_at' => null,
            'running_lock' => 1,
        ]);
    }

    /**
     * Two simultaneous requests can both see no running timer; the unique
     * index rejects the second one, reported like any other conflict.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function guardRace(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['timer' => 'Outro cronômetro foi iniciado ao mesmo tempo. Atualize a página.']);
        }
    }
}
