<?php

namespace App\Http\Controllers\Household;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ExpensePresenter;
use App\Http\Requests\Finance\UpdateSalariesRequest;
use App\Models\AuditLog;
use App\Services\ProportionalSplitter;
use App\Services\SalaryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SalaryController extends Controller
{
    use InteractsWithHousehold;

    public function edit(Request $request, ProportionalSplitter $splitter): Response
    {
        $household = $this->household($request);
        $salaries = $household->salaries()->all();

        $history = AuditLog::query()
            ->where('household_id', $household->id)
            ->where('action', 'salaries.updated')
            ->with('user:id,name')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'user' => $log->user?->name,
                'old' => $log->old_values['salaries'] ?? [],
                'new' => $log->new_values['salaries'] ?? [],
                'recalculated' => $log->new_values['recalculated_pending_occurrences'] ?? 0,
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        return Inertia::render('household/salaries', [
            'members' => ExpensePresenter::for($this->user($request), $household)->members(),
            'combinedSalaryCents' => array_sum($salaries),
            'proportions' => $splitter->basisPoints($salaries),
            'history' => $history,
        ]);
    }

    public function update(UpdateSalariesRequest $request, SalaryService $salaries): RedirectResponse
    {
        $recalculated = $salaries->update($request->household(), $request->salaries(), $this->user($request));

        $this->toast($recalculated === 1
            ? 'Salários atualizados. 1 despesa pendente foi recalculada.'
            : "Salários atualizados. {$recalculated} despesas pendentes foram recalculadas.");

        return back();
    }
}
