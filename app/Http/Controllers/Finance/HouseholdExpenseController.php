<?php

namespace App\Http\Controllers\Finance;

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
use App\Services\Bank\BankCatalogService;
use App\Services\ExpenseService;
use App\Services\HouseholdSummary;
use App\Services\PaymentService;
use App\Support\Month;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class HouseholdExpenseController extends Controller
{
    use InteractsWithHousehold;

    /**
     * Despesas da casa. Personal bills were merged into the personal expense
     * entries; links to them (/expenses?scope=personal, /personal/bills)
     * land in Finanças pessoais › Despesas.
     */
    public function index(Request $request, HouseholdSummary $summary, BankCatalogService $catalog): Response|RedirectResponse
    {
        if ($request->query('scope') === 'personal') {
            return to_route('personal.transactions.index', 'expenses');
        }

        $user = $this->user($request);
        $household = $this->household($request);
        $month = Month::fromInput($request->query('month'));

        $this->ensureGenerated($household, $month);

        $filters = $this->filters($request);
        $query = $this->filteredQuery($user, $filters);

        $occurrences = (clone $query)
            ->with([...ExpensePresenter::RELATIONS, 'paymentEvents.user:id,name'])
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        $presenter = ExpensePresenter::for($user, $household);
        $payable = $this->payableQuery($user, $query);

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
            'awaiting_amount_count' => (clone $query)->awaitingAmount()->count(),
            'payable_count' => (clone $payable)->count(),
            'payable_cents' => (int) (clone $payable)->sum('amount_cents'),
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
            'bankInboxCount' => $catalog->pendingOf($user)->count(),
        ]);
    }

    public function store(ExpenseRequest $request, ExpenseService $expenses): RedirectResponse
    {
        $expenses->create($request->household(), $this->user($request), $request->toData(), $request->installments());

        $this->toast($request->installments() > 1 ? 'Despesa parcelada cadastrada.' : 'Despesa cadastrada.');

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
     * Take a pending household expense into the user's personal finances.
     */
    public function moveToPersonal(Request $request, ExpenseOccurrence $occurrence, ExpenseService $expenses): RedirectResponse
    {
        Gate::authorize('moveToPersonal', $occurrence);

        $entry = $expenses->moveToPersonal($occurrence, $this->user($request));

        $this->toast(
            "“{$entry->title}” agora é uma despesa pessoal sua.",
            description: 'Ela saiu da divisão da casa e está em Finanças pessoais › Despesas.',
        );

        return back();
    }

    /**
     * "Pagar tudo": confirm every pending expense of the month (honoring the
     * filters on screen) that the user is allowed to confirm. Household
     * expenses the user created still wait for the other member, and months
     * without an amount are skipped.
     */
    public function payAll(Request $request, PaymentService $payments): RedirectResponse
    {
        $user = $this->user($request);
        $filters = $this->filters($request);

        $occurrences = $this->payableQuery($user, $this->filteredQuery($user, $filters))
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (ExpenseOccurrence $occurrence) => $user->can('confirmPayment', $occurrence));

        if ($occurrences->isEmpty()) {
            $this->toast('Nenhuma despesa para marcar como paga.', 'info');

            return back();
        }

        $paid = $payments->confirmMany($occurrences, $user);

        $this->toast($paid === 1 ? '1 despesa marcada como paga.' : "{$paid} despesas marcadas como pagas.");

        return back();
    }

    /**
     * The listing filters, read from the query string or the request body.
     *
     * @return array{month: string, creator: ?int, status: ?string, source: ?string}
     */
    private function filters(Request $request): array
    {
        $status = $request->input('status');
        $source = $request->input('source');

        return [
            'month' => Month::key(Month::fromInput($request->input('month'))),
            'creator' => $request->integer('creator') ?: null,
            'status' => in_array($status, ['paid', 'pending', 'overdue', 'awaiting_amount'], true) ? $status : null,
            'source' => in_array($source, ['one_off', 'fixed', 'temporary'], true) ? $source : null,
        ];
    }

    /**
     * Pending expenses of the query the user can confirm: with an amount and
     * created by someone else.
     *
     * @param  Builder<ExpenseOccurrence>  $query
     * @return Builder<ExpenseOccurrence>
     */
    private function payableQuery(User $user, Builder $query): Builder
    {
        return (clone $query)
            ->where('status', OccurrenceStatus::Pending)
            ->where('amount_cents', '>', 0)
            ->where('created_by_user_id', '!=', $user->id);
    }

    /**
     * @param  array{month: string, creator: ?int, status: ?string, source: ?string}  $filters
     * @return Builder<ExpenseOccurrence>
     */
    private function filteredQuery(User $user, array $filters): Builder
    {
        $query = ExpenseOccurrence::query()
            ->forMonth(Month::parse($filters['month']))
            ->household((int) $user->householdId());

        $query->when($filters['creator'], fn (Builder $query, int $creator) => $query->where('created_by_user_id', $creator));

        match ($filters['status']) {
            'paid' => $query->where('status', OccurrenceStatus::Paid),
            'pending' => $query->where('status', OccurrenceStatus::Pending),
            'overdue' => $query->overdue(),
            'awaiting_amount' => $query->awaitingAmount(),
            default => null,
        };

        match ($filters['source']) {
            'one_off' => $query->where('source', OccurrenceSource::OneOff),
            'fixed', 'temporary' => $query->where('source', OccurrenceSource::Recurring)
                ->whereHas('recurringExpense', fn (Builder $query) => $query->where('kind', RecurringKind::from($filters['source']))),
            default => null,
        };

        return $query;
    }
}
