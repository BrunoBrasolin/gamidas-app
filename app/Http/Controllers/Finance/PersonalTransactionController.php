<?php

namespace App\Http\Controllers\Finance;

use App\Enums\TransactionType;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\PersonalTransactionRequest;
use App\Models\PersonalTransaction;
use App\Support\Month;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PersonalTransactionController extends Controller
{
    use InteractsWithHousehold;

    public function index(Request $request, string $type): Response
    {
        $type = TransactionType::fromSlug($type);
        $user = $this->user($request);

        $filters = [
            'period' => $request->query('period') === 'year' ? 'year' : 'month',
            'month' => Month::key(Month::fromInput($request->query('month'))),
        ];

        $month = Month::parse($filters['month']);
        $from = $filters['period'] === 'year' ? $month->startOfYear() : $month;
        $to = $filters['period'] === 'year' ? $month->endOfYear() : $month->endOfMonth();

        $query = PersonalTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()]);

        $transactions = (clone $query)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('personal/transactions', [
            'type' => $type->value,
            'slug' => $type->slug(),
            'filters' => $filters,
            'total_cents' => (int) (clone $query)->sum('amount_cents'),
            'transactions' => $transactions->through(fn (PersonalTransaction $transaction) => [
                'id' => $transaction->id,
                'title' => $transaction->title,
                'amount_cents' => $transaction->amount_cents,
                'transaction_date' => $transaction->transaction_date->toDateString(),
                'notes' => $transaction->notes,
                'created_at' => $transaction->created_at?->toIso8601String(),
                'updated_at' => $transaction->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(PersonalTransactionRequest $request, string $type): RedirectResponse
    {
        $type = TransactionType::fromSlug($type);

        $this->user($request)->personalTransactions()->create([
            ...$request->toData(),
            'type' => $type,
        ]);

        $this->toast('Lançamento registrado.');

        return back();
    }

    public function update(PersonalTransactionRequest $request, PersonalTransaction $transaction): RedirectResponse
    {
        Gate::authorize('update', $transaction);

        $transaction->update($request->toData());

        $this->toast('Lançamento atualizado.');

        return back();
    }

    public function destroy(PersonalTransaction $transaction): RedirectResponse
    {
        Gate::authorize('delete', $transaction);

        $transaction->delete();

        $this->toast('Lançamento excluído.');

        return back();
    }
}
