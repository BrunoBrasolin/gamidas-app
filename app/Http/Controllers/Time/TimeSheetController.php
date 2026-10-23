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
 * The weekly or monthly time sheet: dates × categories, each cell holding
 * the time worked and a Markdown note.
 */
class TimeSheetController extends Controller
{
    use InteractsWithHousehold;

    public function __invoke(Request $request, TimeSummary $summary): Response
    {
        $user = $this->user($request);
        $view = $request->query('view') === 'month' ? 'month' : 'week';
        $anchor = Day::fromInput($request->query('date'));
        $sort = $request->query('sort') === 'total' ? 'total' : 'name';
        $categoryId = $request->integer('category') ?: null;
        $categoryId = $categoryId !== null && $user->timeCategories()->whereKey($categoryId)->exists() ? $categoryId : null;

        if ($view === 'month') {
            $from = $anchor->startOfMonth();
            $to = $anchor->endOfMonth()->startOfDay();
            $previous = $from->subMonthNoOverflow();
            $next = $from->addMonthNoOverflow();
        } else {
            $from = Day::startOfWeek($anchor);
            $to = $from->addDays(6);
            $previous = $from->subWeek();
            $next = $from->addWeek();
        }

        return Inertia::render('time/sheet', [
            'filters' => [
                'view' => $view,
                'date' => $anchor->toDateString(),
                'category' => $categoryId,
                'sort' => $sort,
            ],
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'previous' => $previous->toDateString(),
                'next' => $next->toDateString(),
            ],
            'sheet' => $summary->sheet($user, $from, $to, $categoryId, $sort),
            'category_options' => $summary->categoryOptions($user),
            'timers' => $summary->timers($user),
        ]);
    }
}
