<?php

namespace App\Http\Controllers\Finance;

use App\Enums\TransactionType;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\PersonalTransactionRequest;
use App\Models\PersonalTransaction;
use App\Support\Month;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PersonalTransactionController extends Controller
{
    use InteractsWithHousehold;

    /**
     * Category suggestions offered for each type of personal transaction.
     * Contributions to household expenses are not suggested: the member's
     * share is included automatically in the personal summary.
     */
    private const SUGGESTED_CATEGORIES = [
        'income' => ['Salário', 'Distribuição de lucros', 'Renda extra', 'Outras receitas'],
        'expense' => ['Academia', 'Educação', 'Cartão de crédito', 'Saúde', 'Transporte', 'Lazer', 'Outras despesas'],
        'investment' => ['Renda fixa', 'Tesouro Direto', 'Ações', 'Fundos', 'Previdência', 'Reserva de emergência'],
    ];

    public function index(Request $request, string $type): Response
    {
        $type = TransactionType::fromSlug($type);
        $user = $this->user($request);

        $filters = [
            'period' => $request->query('period') === 'year' ? 'year' : 'month',
            'month' => Month::key(Month::fromInput($request->query('month'))),
            'category' => is_string($request->query('category')) && $request->query('category') !== '' ? $request->query('category') : null,
        ];

        $month = Month::parse($filters['month']);
        $from = $filters['period'] === 'year' ? $month->startOfYear() : $month;
        $to = $filters['period'] === 'year' ? $month->endOfYear() : $month->endOfMonth();

        $query = PersonalTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->when($filters['category'], fn (Builder $query, string $category) => $query->where('category', $category));

        $transactions = (clone $query)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $usedCategories = PersonalTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return Inertia::render('personal/transactions', [
            'type' => $type->value,
            'slug' => $type->slug(),
            'filters' => $filters,
            'total_cents' => (int) (clone $query)->sum('amount_cents'),
            'transactions' => $transactions->through(fn (PersonalTransaction $transaction) => [
                'id' => $transaction->id,
                'category' => $transaction->category,
                'description' => $transaction->description,
                'amount_cents' => $transaction->amount_cents,
                'transaction_date' => $transaction->transaction_date->toDateString(),
                'notes' => $transaction->notes,
                'created_at' => $transaction->created_at?->toIso8601String(),
                'updated_at' => $transaction->updated_at?->toIso8601String(),
            ]),
            'categories' => $usedCategories,
            'suggestedCategories' => collect(self::SUGGESTED_CATEGORIES[$type->value])
                ->merge($usedCategories)
                ->unique()
                ->sort()
                ->values(),
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
