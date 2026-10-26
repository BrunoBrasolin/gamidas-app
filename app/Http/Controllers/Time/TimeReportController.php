<?php

namespace App\Http\Controllers\Time;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Services\TimeSummary;
use App\Support\Day;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reports of recorded time, summed from the time sheet cells.
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

        $categoryId = $request->integer('category') ?: null;
        $categoryId = $categoryId !== null && $user->timeCategories()->whereKey($categoryId)->exists() ? $categoryId : null;

        $days = (int) $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);

        $byDay = $summary->secondsByDay($user, $from, $to, $categoryId);
        $byCategory = $summary->secondsByCategory($user, $from, $to, $categoryId);
        $total = array_sum($byDay);
        $categories = $user->timeCategories()->get()->keyBy('id');
        $goals = $summary->goals($user, $from, $to, $categoryId);

        arsort($byCategory);

        return Inertia::render('time/reports', [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'category' => $categoryId,
            ],
            'fixed' => [
                'today_seconds' => $summary->total($user, $today, $today, $categoryId),
                'week_seconds' => $summary->total($user, Day::startOfWeek($today), $today, $categoryId),
                'month_seconds' => $summary->total($user, $today->startOfMonth(), $today, $categoryId),
            ],
            'period' => [
                'days' => $days,
                'days_with_time' => count($byDay),
                'total_seconds' => $total,
                'average_seconds' => intdiv($total, $days),
                'average_active_seconds' => $byDay === [] ? 0 : intdiv($total, count($byDay)),
                'previous' => [
                    'from' => $previousFrom->toDateString(),
                    'to' => $previousTo->toDateString(),
                    'total_seconds' => $summary->total($user, $previousFrom, $previousTo, $categoryId),
                ],
            ],
            'goals' => $goals['summary'],
            'by_day' => $goals['days'],
            'by_category' => array_map(fn (int $id, int $seconds) => [
                'category_id' => $id,
                'name' => $categories->get($id)->name ?? '',
                'archived' => $categories->get($id)?->isArchived() ?? false,
                'seconds' => $seconds,
                // Basis points of the period total (2500 = 25%).
                'share' => $total > 0 ? intdiv($seconds * 10_000, $total) : 0,
            ], array_keys($byCategory), $byCategory),
            'heatmap' => $summary->heatmap($user, $today, $categoryId),
            'category_options' => $summary->categoryOptions($user),
        ]);
    }
}
