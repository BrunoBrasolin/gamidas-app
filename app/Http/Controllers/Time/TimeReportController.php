<?php

namespace App\Http\Controllers\Time;

use App\Enums\TaskGoalType;
use App\Enums\TaskStatus;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\TimeSummary;
use App\Support\Day;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reports of recorded time. Every number comes from manual time entries.
 */
class TimeReportController extends Controller
{
    use InteractsWithHousehold;

    /**
     * Longest period a report covers.
     */
    private const MAX_DAYS = 366;

    public function __invoke(Request $request, TimeSummary $summary): Response
    {
        $user = $this->user($request);
        $today = Day::today();

        $from = Day::tryParse($request->query('from')) ?? $today->startOfMonth();
        $to = Day::tryParse($request->query('to')) ?? $today;

        if ($from->gt($to) || $from->diffInDays($to) >= self::MAX_DAYS) {
            $from = $today->startOfMonth();
            $to = $today;
        }

        $taskId = $request->integer('task') ?: null;
        $taskId = $taskId !== null && $user->tasks()->whereKey($taskId)->exists() ? $taskId : null;

        $days = (int) $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);

        $byDay = $summary->minutesByDay($user, $from, $to, $taskId);
        $byTask = $summary->minutesByTask($user, $from, $to, $taskId);
        $total = array_sum($byDay);
        $tasks = $user->tasks()->get()->keyBy('id');

        $goalTasks = $user->tasks()
            ->where('goal_type', '!=', TaskGoalType::None)
            ->when($taskId !== null, fn ($query) => $query->whereKey($taskId))
            ->orderByRaw('archived_at is not null')
            ->orderByRaw('completed_at is not null')
            ->orderBy('title')
            ->get();

        arsort($byTask);

        return Inertia::render('time/reports', [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'task' => $taskId,
            ],
            'fixed' => [
                'today_minutes' => $summary->total($user, $today, $today, $taskId),
                'week_minutes' => $summary->total($user, Day::startOfWeek($today), $today, $taskId),
                'month_minutes' => $summary->total($user, $today->startOfMonth(), $today, $taskId),
            ],
            'period' => [
                'days' => $days,
                'days_with_entries' => count($byDay),
                'total_minutes' => $total,
                'average_minutes' => intdiv($total, $days),
                'average_active_minutes' => $byDay === [] ? 0 : intdiv($total, count($byDay)),
                'previous' => [
                    'from' => $previousFrom->toDateString(),
                    'to' => $previousTo->toDateString(),
                    'total_minutes' => $summary->total($user, $previousFrom, $previousTo, $taskId),
                ],
            ],
            'by_day' => array_map(fn (string $date) => ['date' => $date, 'minutes' => $byDay[$date] ?? 0], Day::range($from, $to)),
            'by_task' => array_map(fn (int $id, int $minutes) => [
                'task_id' => $id,
                'title' => $tasks->get($id)->title ?? '',
                'status' => $tasks->get($id)?->status()->value,
                'minutes' => $minutes,
                // Basis points of the period total (2500 = 25%).
                'share' => $total > 0 ? intdiv($minutes * 10_000, $total) : 0,
            ], array_keys($byTask), $byTask),
            'goals' => $summary->tasks($goalTasks),
            'task_counts' => [
                TaskStatus::Active->value => $tasks->filter(fn (Task $task) => $task->status() === TaskStatus::Active)->count(),
                TaskStatus::Completed->value => $tasks->filter(fn (Task $task) => $task->status() === TaskStatus::Completed)->count(),
                TaskStatus::Archived->value => $tasks->filter(fn (Task $task) => $task->status() === TaskStatus::Archived)->count(),
            ],
            'heatmap' => $summary->heatmap($user, $today, $taskId),
            'task_options' => $summary->taskOptions($user),
        ]);
    }
}
