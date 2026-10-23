<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\DailyNote;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Day;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-side aggregations of the time-tracking module: the time sheet, task
 * goal progress and reports. Every total here is computed from manual time
 * entries only; timer sessions are never counted as recorded time.
 */
class TimeSummary
{
    /**
     * Minutes recorded per day ("Y-m-d" => minutes), only for days with entries.
     *
     * @return array<string, int>
     */
    public function minutesByDay(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $taskId = null): array
    {
        return $this->entries($user, $from, $to, $taskId)
            ->groupBy('entry_date')
            ->select('entry_date', DB::raw('sum(duration_minutes) as minutes'))
            ->get()
            ->mapWithKeys(fn (TimeEntry $row) => [$row->entry_date->toDateString() => (int) $row->getAttribute('minutes')])
            ->all();
    }

    /**
     * Minutes recorded per task (task id => minutes) in a period.
     *
     * @return array<int, int>
     */
    public function minutesByTask(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $taskId = null): array
    {
        return $this->entries($user, $from, $to, $taskId)
            ->groupBy('task_id')
            ->select('task_id', DB::raw('sum(duration_minutes) as minutes'))
            ->pluck('minutes', 'task_id')
            ->map(fn ($minutes) => (int) $minutes)
            ->all();
    }

    public function total(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $taskId = null): int
    {
        return (int) $this->entries($user, $from, $to, $taskId)->sum('duration_minutes');
    }

    /**
     * Every task of the user for selects and filters, archived ones last.
     *
     * @return list<array{id: int, title: string, status: string}>
     */
    public function taskOptions(User $user): array
    {
        return array_values(Task::query()
            ->ownedBy($user)
            ->orderByRaw('archived_at is not null')
            ->orderBy('title')
            ->get()
            ->map(fn (Task $task) => ['id' => $task->id, 'title' => $task->title, 'status' => $task->status()->value])
            ->all());
    }

    /**
     * The page payload of tasks with their goal progress, computed from all
     * the time ever recorded for them, in two grouped queries.
     *
     * @param  Collection<int, Task>  $tasks
     * @return list<array<string, mixed>>
     */
    public function tasks(Collection $tasks): array
    {
        $ids = $tasks->modelKeys();

        $recorded = TimeEntry::query()
            ->whereIn('task_id', $ids)
            ->groupBy('task_id')
            ->select('task_id', DB::raw('sum(duration_minutes) as minutes'), DB::raw('count(*) as entries'))
            ->get()
            ->keyBy('task_id');

        $sessions = DB::table('timer_sessions')
            ->whereIn('task_id', $ids)
            ->groupBy('task_id')
            ->selectRaw('task_id, count(*) as aggregate')
            ->pluck('aggregate', 'task_id');

        return array_values($tasks->map(function (Task $task) use ($recorded, $sessions) {
            $row = $recorded->get($task->id);

            return $this->task(
                $task,
                (int) $row?->getAttribute('minutes'),
                (int) $row?->getAttribute('entries') > 0 || (int) ($sessions[$task->id] ?? 0) > 0,
            );
        })->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function task(Task $task, int $recordedMinutes, bool $hasHistory): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'goal_type' => $task->goal_type->value,
            'target_minutes' => $task->target_minutes,
            'outcome' => $task->outcome,
            'deadline' => $task->deadline?->toDateString(),
            'status' => $task->status()->value,
            'completed_at' => $task->completed_at?->toIso8601String(),
            'archived_at' => $task->archived_at?->toIso8601String(),
            'created_at' => $task->created_at?->toIso8601String(),
            'has_history' => $hasHistory,
            'progress' => $this->progress($task, $recordedMinutes),
        ];
    }

    /**
     * Goal progress of a task. Time goals compare the recorded minutes with
     * the target; outcome goals are reached when the task is completed.
     *
     * @return array{recorded_minutes: int, percent: ?int, remaining_minutes: ?int, outcome_done: ?bool, overdue: bool, days_left: ?int}
     */
    public function progress(Task $task, int $recordedMinutes): array
    {
        $target = $task->goal_type->hasTimeTarget() ? $task->target_minutes : null;
        $done = $task->completed_at !== null;
        $percent = match (true) {
            $target !== null && $target > 0 => intdiv($recordedMinutes * 100, $target),
            $task->goal_type->hasOutcome() => $done ? 100 : 0,
            default => null,
        };
        $today = Day::today();

        return [
            'recorded_minutes' => $recordedMinutes,
            'percent' => $percent,
            'remaining_minutes' => $target === null ? null : max(0, $target - $recordedMinutes),
            'outcome_done' => $task->goal_type->hasOutcome() ? $done : null,
            'overdue' => $task->deadline !== null && ! $done && $task->deadline->lt($today),
            'days_left' => $task->deadline === null ? null : (int) $today->diffInDays($task->deadline, false),
        ];
    }

    /**
     * The time sheet of a period: one row per date, one column per task, and
     * the recorded entries of every cell.
     *
     * Columns are the tasks with entries in the period plus the active tasks,
     * so archived and completed tasks still show their history.
     *
     * @return array<string, mixed>
     */
    public function sheet(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $taskId, string $sort): array
    {
        $entries = $this->entries($user, $from, $to, $taskId)
            ->orderBy('id')
            ->get(['id', 'task_id', 'entry_date', 'duration_minutes', 'description', 'updated_at']);

        $taskIds = $entries->pluck('task_id')->unique()->all();

        $columns = Task::query()
            ->ownedBy($user)
            ->when($taskId !== null, fn (Builder $query) => $query->whereKey($taskId))
            ->where(fn (Builder $query) => $query
                ->whereIn('id', $taskIds)
                ->orWhere(fn (Builder $query) => $query->withStatus(TaskStatus::Active)))
            ->orderBy('title')
            ->get();

        $cells = [];
        $byDay = [];
        $byTask = [];

        foreach ($entries as $entry) {
            $date = $entry->entry_date->toDateString();
            $cells[$date][$entry->task_id]['minutes'] = ($cells[$date][$entry->task_id]['minutes'] ?? 0) + $entry->duration_minutes;
            $cells[$date][$entry->task_id]['entries'][] = $entry->toPage();
            $byDay[$date] = ($byDay[$date] ?? 0) + $entry->duration_minutes;
            $byTask[$entry->task_id] = ($byTask[$entry->task_id] ?? 0) + $entry->duration_minutes;
        }

        if ($sort === 'total') {
            $columns = $columns->sortByDesc(fn (Task $task) => $byTask[$task->id] ?? 0)->values();
        }

        $notes = DailyNote::query()
            ->where('user_id', $user->id)
            ->whereBetween('note_date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn (DailyNote $note) => $note->note_date->toDateString());

        $today = Day::today()->toDateString();

        return [
            'days' => array_map(fn (string $date) => [
                'date' => $date,
                'is_today' => $date === $today,
                'total_minutes' => $byDay[$date] ?? 0,
                'note' => $notes->has($date) ? $notes->get($date)?->preview() : null,
            ], Day::range($from, $to)),
            'tasks' => array_values($columns->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status()->value,
                'total_minutes' => $byTask[$task->id] ?? 0,
            ])->all()),
            'cells' => (object) $cells,
            'total_minutes' => array_sum($byDay),
        ];
    }

    /**
     * Recorded minutes for every day of the 53 weeks ending on the week of
     * $today (a calendar heatmap), only for days with entries.
     *
     * @return array{from: string, to: string, days: array<string, int>}
     */
    public function heatmap(User $user, CarbonImmutable $today, ?int $taskId = null): array
    {
        $to = $today->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay();
        $from = Day::startOfWeek($today)->subWeeks(52);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $this->minutesByDay($user, $from, $to, $taskId),
        ];
    }

    /**
     * @return Builder<TimeEntry>
     */
    private function entries(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $taskId = null): Builder
    {
        return TimeEntry::query()
            ->where('user_id', $user->id)
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->when($taskId !== null, fn (Builder $query) => $query->where('task_id', $taskId));
    }
}
