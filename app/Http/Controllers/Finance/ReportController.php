<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ExpensePresenter;
use App\Services\HouseholdSummary;
use App\Services\PersonalSummary;
use App\Support\Month;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    use InteractsWithHousehold;

    public function __invoke(Request $request, HouseholdSummary $householdSummary, PersonalSummary $personalSummary): Response
    {
        $user = $this->user($request);
        $household = $this->household($request);
        $year = $request->integer('year') ?: Month::current()->year;
        $year = $year >= 2000 && $year <= 2100 ? $year : Month::current()->year;

        return Inertia::render('reports/index', [
            'year' => $year,
            'members' => ExpensePresenter::for($user, $household)->members(),
            'household' => $householdSummary->year($household, $year),
            'personal' => $personalSummary->year($user, $year),
        ]);
    }
}
