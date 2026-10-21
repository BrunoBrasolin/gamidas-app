<?php

namespace App\Http\Controllers\Finance;

use App\Enums\OccurrenceStatus;
use App\Enums\RecurringKind;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\EndRecurringExpenseRequest;
use App\Http\Requests\Finance\RecurringExpenseRequest;
use App\Models\ExpenseOccurrence;
use App\Models\RecurringExpense;
use App\Services\RecurringExpenseService;
use App\Support\Month;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RecurringExpenseController extends Controller
{
    use InteractsWithHousehold;

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $household = $this->household($request);

        $filters = [
            'kind' => in_array($request->query('kind'), ['fixed', 'temporary'], true) ? $request->query('kind') : null,
            'status' => in_array($request->query('status'), ['active', 'ended'], true) ? $request->query('status') : 'active',
        ];

        $current = Month::current()->toDateString();

        $templates = RecurringExpense::query()
            ->visibleTo($user)
            ->when($filters['kind'], fn (Builder $query, string $kind) => $query->where('kind', $kind))
            ->when($filters['status'] === 'active', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereNull('end_month')->orWhere('end_month', '>=', $current)))
            ->when($filters['status'] === 'ended', fn (Builder $query) => $query->where('end_month', '<', $current))
            ->with([
                'category:id,name',
                'creator:id,name',
                'editor:id,name',
                'occurrences' => fn ($query) => $query
                    ->select(['id', 'recurring_expense_id', 'billing_month', 'due_date', 'amount_cents', 'status', 'is_customized', 'paid_at'])
                    ->orderBy('billing_month'),
            ])
            ->withCount([
                'occurrences as paid_count' => fn (Builder $query) => $query->where('status', OccurrenceStatus::Paid),
                'occurrences as generated_count',
            ])
            ->orderBy('kind')
            ->orderBy('title')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('recurring-expenses/index', [
            'filters' => $filters,
            'currentMonth' => Month::key(Month::current()),
            'templates' => $templates->through(fn (RecurringExpense $template) => [
                'id' => $template->id,
                'kind' => $template->kind->value,
                'scope' => $template->scope->value,
                'title' => $template->title,
                'notes' => $template->notes,
                'category' => $template->category ? ['id' => $template->category->id, 'name' => $template->category->name] : null,
                'amount_cents' => $template->amount_cents,
                'due_day' => $template->due_day,
                'start_month' => Month::key($template->start_month),
                'end_month' => $template->end_month ? Month::key($template->end_month) : null,
                'total_months' => $template->total_months,
                'is_active' => $template->isActive(),
                'paid_count' => (int) $template->getAttribute('paid_count'),
                'generated_count' => (int) $template->getAttribute('generated_count'),
                'created_by' => $template->creator->name,
                'created_at' => $template->created_at?->toIso8601String(),
                'updated_by' => $template->editor?->name,
                'updated_at' => $template->updated_at?->toIso8601String(),
                'occurrences' => $template->occurrences->map(fn (ExpenseOccurrence $occurrence) => [
                    'id' => $occurrence->id,
                    'billing_month' => Month::key($occurrence->billing_month),
                    'due_date' => $occurrence->due_date->toDateString(),
                    'amount_cents' => $occurrence->amount_cents,
                    'status' => $occurrence->status->value,
                    'is_customized' => $occurrence->is_customized,
                ])->all(),
            ]),
            'categories' => $household->categories()->get(['id', 'name']),
        ]);
    }

    public function store(RecurringExpenseRequest $request, RecurringExpenseService $service): RedirectResponse
    {
        $data = $request->toCreateData();
        $service->create($request->household(), $this->user($request), $data);

        $this->toast($data['kind'] === RecurringKind::Temporary
            ? 'Despesa extra temporária cadastrada.'
            : 'Despesa fixa cadastrada.');

        return back();
    }

    public function update(RecurringExpenseRequest $request, RecurringExpense $recurringExpense, RecurringExpenseService $service): RedirectResponse
    {
        Gate::authorize('update', $recurringExpense);

        $service->update(
            $recurringExpense,
            $this->user($request),
            $request->toData(),
            $request->month('effective_month') ?? Month::current(),
        );

        $this->toast('Despesa recorrente atualizada.');

        return back();
    }

    public function end(EndRecurringExpenseRequest $request, RecurringExpense $recurringExpense, RecurringExpenseService $service): RedirectResponse
    {
        Gate::authorize('update', $recurringExpense);

        $service->end($recurringExpense, $this->user($request), $request->month('end_month') ?? Month::current());

        $this->toast('Despesa recorrente encerrada.');

        return back();
    }

    public function destroy(Request $request, RecurringExpense $recurringExpense, RecurringExpenseService $service): RedirectResponse
    {
        Gate::authorize('delete', $recurringExpense);

        $service->delete($recurringExpense, $this->user($request));

        $this->toast('Despesa recorrente excluída.');

        return back();
    }
}
