<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle of personal tasks. Time entries and timer sessions reference the
 * task by id, so renaming, completing or archiving a task never changes its
 * history, and a task with history can only be archived, not deleted.
 */
class TaskService
{
    public function __construct(private readonly TimerService $timer)
    {
        //
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): Task
    {
        return $user->tasks()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Task $task, array $data): Task
    {
        $task->update($data);

        return $task;
    }

    public function complete(Task $task): void
    {
        if ($task->completed_at === null) {
            $task->update(['completed_at' => Date::now()]);
        }
    }

    public function reopen(Task $task): void
    {
        $task->update(['completed_at' => null]);
    }

    /**
     * Archive the task, stopping its open timer sessions so no timer keeps
     * running for a hidden task. Returns how many sessions were stopped.
     */
    public function archive(Task $task): int
    {
        return DB::transaction(function () use ($task) {
            $stopped = $this->timer->stopForTask($task);

            if ($task->archived_at === null) {
                $task->update(['archived_at' => Date::now()]);
            }

            return $stopped;
        });
    }

    public function restore(Task $task): void
    {
        $task->update(['archived_at' => null]);
    }

    public function hasHistory(Task $task): bool
    {
        return $task->timeEntries()->exists() || $task->timerSessions()->exists();
    }

    /**
     * Permanently delete a task that has no recorded time and no timer
     * sessions. Tasks with history must be archived instead (the foreign keys
     * also restrict the deletion at the database level).
     */
    public function delete(Task $task): void
    {
        DB::transaction(function () use ($task) {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);

            if ($this->hasHistory($task)) {
                throw ValidationException::withMessages([
                    'task' => 'Esta tarefa tem tempo registrado ou sessões do cronômetro. Arquive-a para manter o histórico.',
                ]);
            }

            $task->delete();
        });
    }
}
