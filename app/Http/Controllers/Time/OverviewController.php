<?php

namespace App\Http\Controllers\Time;

use App\Enums\TaskStatus;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Models\TimeEntry;
use App\Services\DailyNoteService;
use App\Services\TimeSummary;
use App\Support\Day;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    use InteractsWithHousehold;

    public function __invoke(Request $request, TimeSummary $summary, DailyNoteService $notes): Response
    {
        $user = $this->user($request);
        $today = Day::today();
        $weekStart = Day::startOfWeek($today);
        $weekEnd = $weekStart->addDays(6);
        $week = $summary->minutesByDay($user, $weekStart, $weekEnd);

        $activeTasks = $user->tasks()
            ->withStatus(TaskStatus::Active)
            ->orderByRaw('deadline is null')
            ->orderBy('deadline')
            ->orderBy('title')
            ->get();

        $recent = TimeEntry::query()
            ->with('task')
            ->where('user_id', $user->id)
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        $note = $notes->find($user, $today);

        return Inertia::render('time/index', [
            'today' => $today->toDateString(),
            'today_minutes' => $week[$today->toDateString()] ?? 0,
            'month_minutes' => $summary->total($user, $today->startOfMonth(), $today),
            'week' => [
                'from' => $weekStart->toDateString(),
                'to' => $weekEnd->toDateString(),
                'total_minutes' => array_sum($week),
                'days' => array_map(fn (string $date) => [
                    'date' => $date,
                    'minutes' => $week[$date] ?? 0,
                ], Day::range($weekStart, $weekEnd)),
            ],
            'tasks' => $summary->tasks($activeTasks),
            'recent_entries' => $recent->map(fn (TimeEntry $entry) => [
                ...$entry->toPage(),
                'task_title' => $entry->task->title,
            ])->values(),
            'today_note' => $note?->preview(200),
        ]);
    }
}
