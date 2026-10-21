<?php

namespace App\Http\Controllers\Finance;

use App\Enums\OccurrenceStatus;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ExpensePresenter;
use App\Models\ExpenseOccurrence;
use App\Services\CalendarService;
use App\Services\HouseholdSummary;
use App\Services\PersonalSummary;
use App\Services\ProportionalSplitter;
use App\Support\Month;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use InteractsWithHousehold;

    /**
     * Number of days ahead that the dashboard lists calendar events.
     */
    private const UPCOMING_EVENT_DAYS = 30;

    public function __invoke(
        Request $request,
        HouseholdSummary $householdSummary,
        PersonalSummary $personalSummary,
        ProportionalSplitter $splitter,
        CalendarService $calendar,
    ): Response {
        $user = $this->user($request);
        $household = $this->household($request);
        $month = Month::fromInput($request->query('month'));

        $this->ensureGenerated($household, $month);

        $presenter = ExpensePresenter::for($user, $household);
        $salaries = $household->salaries()->all();

        $upcoming = ExpenseOccurrence::query()
            ->visibleTo($user)
            ->where('status', OccurrenceStatus::Pending)
            ->where('due_date', '<=', Date::today()->addDays((int) config('finance.upcoming_days'))->toDateString())
            ->with(ExpensePresenter::RELATIONS)
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(12)
            ->get();

        $events = array_slice($calendar->between($household, Date::today(), Date::today()->addDays(self::UPCOMING_EVENT_DAYS)), 0, 6);

        return Inertia::render('dashboard', [
            'month' => Month::key($month),
            'members' => $presenter->members(),
            'combinedSalaryCents' => array_sum($salaries),
            'proportions' => $splitter->basisPoints($salaries),
            'household' => $householdSummary->month($household, $month),
            'personal' => $personalSummary->month($user, $month),
            'upcoming' => $presenter->occurrences($upcoming),
            'events' => $events,
        ]);
    }
}
