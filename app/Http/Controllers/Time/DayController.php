<?php

namespace App\Http\Controllers\Time;

use App\Enums\TimerStatus;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Models\TimeEntry;
use App\Models\TimerSession;
use App\Services\DailyNoteService;
use App\Services\TimeEntryService;
use App\Services\TimeSummary;
use App\Support\Day;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One day in detail: its manual entries, totals and Markdown note.
 */
class DayController extends Controller
{
    use InteractsWithHousehold;

    public function __invoke(Request $request, string $date, TimeSummary $summary, TimeEntryService $entries, DailyNoteService $notes): Response
    {
        $user = $this->user($request);
        $day = Day::fromRoute($date);

        $dayEntries = TimeEntry::query()
            ->with('task')
            ->where('user_id', $user->id)
            ->where('entry_date', $day->toDateString())
            ->orderBy('id')
            ->get();

        $byTask = $dayEntries
            ->groupBy('task_id')
            ->map(fn ($group) => [
                'task_id' => (int) $group->first()?->task_id,
                'title' => (string) $group->first()?->task->title,
                'minutes' => (int) $group->sum('duration_minutes'),
            ])
            ->sortByDesc('minutes')
            ->values();

        // Timer measurements started this day, offered only as an explicit,
        // editable shortcut for new entries; they are never added to totals.
        $sessions = $user->timerSessions()
            ->with('task')
            ->where('status', TimerStatus::Stopped)
            ->whereBetween('started_at', [$day->utc()->format('Y-m-d H:i:s'), $day->endOfDay()->utc()->format('Y-m-d H:i:s')])
            ->orderBy('started_at')
            ->get();

        return Inertia::render('time/day', [
            'date' => $day->toDateString(),
            'previous' => $day->subDay()->toDateString(),
            'next' => $day->addDay()->toDateString(),
            'is_today' => $day->isSameDay(Day::today()),
            'entries' => $dayEntries->map(fn (TimeEntry $entry) => [
                ...$entry->toPage(),
                'task_title' => $entry->task->title,
                'task_status' => $entry->task->status()->value,
            ])->values(),
            'by_task' => $byTask,
            'total_minutes' => (int) $dayEntries->sum('duration_minutes'),
            'note' => DailyNoteService::toPage($day, $notes->find($user, $day)),
            'copy_source' => $entries->copySource($user, $day),
            'timer_sessions' => $sessions->map(fn (TimerSession $session) => $session->toPage())->values(),
            'task_options' => $summary->taskOptions($user),
        ]);
    }
}
