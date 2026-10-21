<?php

namespace App\Http\Controllers\Finance;

use App\Enums\OccurrenceStatus;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ExpensePresenter;
use App\Http\Requests\Finance\CreditCardRequest;
use App\Models\CreditCard;
use App\Models\CreditCardPurchase;
use App\Models\ExpenseOccurrence;
use App\Services\CreditCardPurchaseService;
use App\Support\Month;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CreditCardController extends Controller
{
    use InteractsWithHousehold;

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $household = $this->household($request);
        $month = Month::fromInput($request->query('month'));
        $presenter = ExpensePresenter::for($user, $household);

        $filters = [
            'month' => Month::key($month),
            'card' => $request->integer('card') ?: null,
            'status' => in_array($request->query('status'), ['open', 'finished'], true) ? $request->query('status') : 'open',
        ];

        $cards = $household->creditCards()->with('holder:id,name')->withCount('purchases')->get();

        $purchases = CreditCardPurchase::query()
            ->visibleTo($user)
            ->when($filters['card'], fn (Builder $query, int $card) => $query->where('credit_card_id', $card))
            ->when($filters['status'] === 'open', fn (Builder $query) => $query->whereHas('installments', fn (Builder $query) => $query
                ->where('status', OccurrenceStatus::Pending)))
            ->when($filters['status'] === 'finished', fn (Builder $query) => $query->whereDoesntHave('installments', fn (Builder $query) => $query
                ->where('status', OccurrenceStatus::Pending)))
            ->with([
                'creditCard:id,name',
                'category:id,name',
                'creator:id,name',
                'installments' => fn ($query) => $query->with(ExpensePresenter::RELATIONS),
            ])
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $invoices = ExpenseOccurrence::query()
            ->visibleTo($user)
            ->forMonth($month)
            ->join('credit_card_purchases', 'credit_card_purchases.id', '=', 'expense_occurrences.credit_card_purchase_id')
            ->selectRaw('credit_card_purchases.credit_card_id, expense_occurrences.status, COUNT(*) as aggregate_count, SUM(expense_occurrences.amount_cents) as aggregate_total')
            ->groupBy('credit_card_purchases.credit_card_id', 'expense_occurrences.status')
            ->get()
            ->groupBy('credit_card_id')
            ->map(fn ($rows, $cardId) => [
                'credit_card_id' => (int) $cardId,
                'total_cents' => (int) $rows->sum('aggregate_total'),
                'paid_cents' => (int) $rows->where('status', OccurrenceStatus::Paid)->sum('aggregate_total'),
                'pending_cents' => (int) $rows->where('status', OccurrenceStatus::Pending)->sum('aggregate_total'),
                'count' => (int) $rows->sum('aggregate_count'),
            ])
            ->values();

        return Inertia::render('credit-cards/index', [
            'filters' => $filters,
            'cards' => $cards->map(fn (CreditCard $card) => [
                'id' => $card->id,
                'name' => $card->name,
                'last_four_digits' => $card->last_four_digits,
                'holder_user_id' => $card->holder_user_id,
                'holder' => $card->holder?->name,
                'due_day' => $card->due_day,
                'is_active' => $card->is_active,
                'purchases_count' => (int) $card->getAttribute('purchases_count'),
            ]),
            'invoices' => $invoices,
            'purchases' => $purchases->through(function (CreditCardPurchase $purchase) use ($presenter) {
                $installments = $presenter->occurrences($purchase->installments);
                $paid = $purchase->installments->where('status', OccurrenceStatus::Paid);

                return [
                    'id' => $purchase->id,
                    'credit_card_id' => $purchase->credit_card_id,
                    'credit_card' => $purchase->creditCard->name,
                    'scope' => $purchase->scope->value,
                    'description' => $purchase->description,
                    'notes' => $purchase->notes,
                    'category' => $purchase->category ? ['id' => $purchase->category->id, 'name' => $purchase->category->name] : null,
                    'purchase_date' => $purchase->purchase_date->toDateString(),
                    'mode' => $purchase->mode->value,
                    'total_amount_cents' => $purchase->total_amount_cents,
                    'installment_count' => $purchase->installment_count,
                    'installment_amount_cents' => $purchase->installments->first()->amount_cents ?? 0,
                    'first_billing_month' => Month::key($purchase->first_billing_month),
                    'last_billing_month' => Month::key($purchase->lastBillingMonth()),
                    'paid_count' => $paid->count(),
                    'paid_cents' => (int) $paid->sum('amount_cents'),
                    'remaining_count' => $purchase->installments->count() - $paid->count(),
                    'remaining_cents' => (int) $purchase->installments->where('status', OccurrenceStatus::Pending)->sum('amount_cents'),
                    'created_by' => $purchase->creator->name,
                    'created_at' => $purchase->created_at?->toIso8601String(),
                    'can_change_schedule' => $paid->isEmpty(),
                    'installments' => $installments,
                ];
            }),
            'members' => $presenter->members(),
            'categories' => $household->categories()->get(['id', 'name']),
        ]);
    }

    public function store(CreditCardRequest $request): RedirectResponse
    {
        $household = $request->household();

        CreditCard::create([
            ...$request->toData(),
            'household_id' => $household->id,
            'created_by_user_id' => $this->user($request)->id,
        ]);

        $this->toast('Cartão cadastrado.');

        return back();
    }

    public function update(CreditCardRequest $request, CreditCard $creditCard, CreditCardPurchaseService $purchases): RedirectResponse
    {
        Gate::authorize('update', $creditCard);

        DB::transaction(function () use ($request, $creditCard, $purchases) {
            $creditCard->update($request->toData());

            if ($creditCard->wasChanged('due_day')) {
                $purchases->syncDueDates($creditCard);
            }
        });

        $this->toast('Cartão atualizado.');

        return back();
    }

    public function destroy(CreditCard $creditCard): RedirectResponse
    {
        Gate::authorize('delete', $creditCard);

        if ($creditCard->purchases()->exists()) {
            throw ValidationException::withMessages([
                'credit_card' => 'Este cartão tem compras registradas. Desative-o em vez de excluí-lo.',
            ]);
        }

        $creditCard->delete();

        $this->toast('Cartão excluído.');

        return back();
    }
}
