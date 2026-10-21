<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Models\ExpenseOccurrence;
use App\Services\PersonalSummary;
use App\Support\Month;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PersonalFinanceController extends Controller
{
    use InteractsWithHousehold;

    public function __invoke(Request $request, PersonalSummary $summary): Response
    {
        $user = $this->user($request);
        $view = $request->query('view') === 'year' ? 'year' : 'month';
        $month = Month::fromInput($request->query('month'));
        $year = $request->integer('year') ?: $month->year;
        $year = $year >= 2000 && $year <= 2100 ? $year : $month->year;

        if ($view === 'month') {
            $this->ensureGenerated($this->household($request), $month);
        }

        $bills = $view === 'month'
            ? ExpenseOccurrence::query()
                ->personalOf($user)
                ->forMonth($month)
                ->orderBy('due_date')
                ->get()
                ->map(fn (ExpenseOccurrence $occurrence) => [
                    'id' => $occurrence->id,
                    'title' => $occurrence->title,
                    'due_date' => $occurrence->due_date->toDateString(),
                    'amount_cents' => $occurrence->amount_cents,
                    'status' => $occurrence->status->value,
                ])
            : [];

        return Inertia::render('personal/index', [
            'view' => $view,
            'month' => Month::key($month),
            'year' => $year,
            'summary' => $view === 'month' ? $summary->month($user, $month) : null,
            'yearly' => $view === 'year' ? $summary->year($user, $year) : null,
            'bills' => $bills,
        ]);
    }
}
