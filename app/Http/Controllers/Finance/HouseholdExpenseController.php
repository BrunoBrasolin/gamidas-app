<?php

namespace App\Http\Controllers\Finance;

use App\Enums\ExpenseScope;
use App\Enums\OccurrenceSource;
use App\Enums\OccurrenceStatus;
use App\Enums\RecurringKind;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ExpensePresenter;
use App\Http\Requests\Finance\ExpenseRequest;
use App\Models\ExpenseOccurrence;
use App\Models\ExpenseShare;
use App\Models\User;
use App\Services\ExpenseService;
use App\Services\HouseholdSummary;
use App\Support\Month;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class HouseholdExpenseController extends Controller
{
    use InteractsWithHousehold;

    public function index(Request $request, HouseholdSummary $summary): Response
    {
        $user = $this->user($request);
        $household = $this->household($request);
        $month = Month::fromInput($request->query('month'));

        $this->ensureGenerated($household, $month);

        $filters = [
            'month' => Month::key($month),
            'scope' => $request->query('scope') === ExpenseScope::Personal->value ? ExpenseScope::Personal->value : ExpenseScope::Household->value,
            'category' => $request->integer('category') ?: null,
            'creator' => $request->integer('creator') ?: null,
            'status' => in_array($request->query('status'), ['paid', 'pending', 'overdue'], true) ? $request->query('status') : null,
            'source' => in_array($request->query('source'), ['one_off', 'fixed', 'temporary', 'credit_card'], true) ? $request->query('source') : null,
        ];

        $query = $this->filteredQuery($user, $filters);

        $occurrences = (clone $query)
            ->with([...ExpensePresenter::RELATIONS, 'paymentEvents.user:id,name'])
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        $presenter = ExpensePresenter::for($user, $household);

        $totals = [
            'total_cents' => (int) (clone $query)->sum('amount_cents'),
            'paid_cents' => (int) (clone $query)->where('status', OccurrenceStatus::Paid)->sum('amount_cents'),
            'pending_cents' => (int) (clone $query)->where('status', OccurrenceStatus::Pending)->sum('amount_cents'),
            'members' => ExpenseShare::query()
                ->whereIn('expense_occurrence_id', (clone $query)->select('expense_occurrences.id'))
                ->selectRaw('user_id, SUM(amount_cents) as aggregate_total')
                ->groupBy('user_id')
                ->pluck('aggregate_total', 'user_id')
                ->map(fn ($total) => (int) $total),
        ];

        return Inertia::render('expenses/index', [
            'filters' => $filters,
            'occurrences' => $occurrences->through(fn (ExpenseOccurrence $occurrence) => [
                ...$presenter->occurrence($occurrence),
                'history' => $occurrence->paymentEvents->map(fn ($event) => [
                    'id' => $event->id,
                    'type' => $event->type->value,
                    'user' => $event->user->name,
                    'reason' => $event->reason,
                    'amount_cents' => $event->amount_cents,
                    'created_at' => $event->created_at->toIso8601String(),
                ])->all(),
            ]),
            'totals' => $totals,
            'summary' => $summary->month($household, $month),
            'members' => $presenter->members(),
            'categories' => $household->categories()->get(['id', 'name']),
        ]);
    }

    public function store(ExpenseRequest $request, ExpenseService $expenses): RedirectResponse
    {
        $expenses->create($request->household(), $this->user($request), [
            ...$request->toData(),
            'scope' => $request->scope(),
        ]);

        $this->toast('Despesa cadastrada.');

        return back();
    }

    public function update(ExpenseRequest $request, ExpenseOccurrence $occurrence, ExpenseService $expenses): RedirectResponse
    {
        Gate::authorize('update', $occurrence);

        $expenses->update($occurrence, $this->user($request), $request->toData());

        $this->toast('Despesa atualizada.');

        return back();
    }

    public function destroy(Request $request, ExpenseOccurrence $occurrence, ExpenseService $expenses): RedirectResponse
    {
        Gate::authorize('delete', $occurrence);

        $expenses->delete($occurrence, $this->user($request));

        $this->toast('Despesa removida.');

        return back();
    }

    /**
     * @param  array{month: string, scope: string, category: ?int, creator: ?int, status: ?string, source: ?string}  $filters
     * @return Builder<ExpenseOccurrence>
     */
    private function filteredQuery(User $user, array $filters): Builder
    {
        $query = ExpenseOccurrence::query()->forMonth(Month::parse($filters['month']));

        if ($filters['scope'] === ExpenseScope::Personal->value) {
            $query->personalOf($user);
        } else {
            $query->household((int) $user->householdId());
        }

        $query->when($filters['category'], fn (Builder $query, int $category) => $query->where('expense_category_id', $category))
            ->when($filters['creator'], fn (Builder $query, int $creator) => $query->where('created_by_user_id', $creator));

        match ($filters['status']) {
            'paid' => $query->where('status', OccurrenceStatus::Paid),
            'pending' => $query->where('status', OccurrenceStatus::Pending),
            'overdue' => $query->where('status', OccurrenceStatus::Pending)->where('due_date', '<', Date::today()->toDateString()),
            default => null,
        };

        match ($filters['source']) {
            'one_off' => $query->where('source', OccurrenceSource::OneOff),
            'credit_card' => $query->where('source', OccurrenceSource::CreditCard),
            'fixed', 'temporary' => $query->where('source', OccurrenceSource::Recurring)
                ->whereHas('recurringExpense', fn (Builder $query) => $query->where('kind', RecurringKind::from($filters['source']))),
            default => null,
        };

        return $query;
    }
}
