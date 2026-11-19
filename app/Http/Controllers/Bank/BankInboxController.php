<?php

namespace App\Http\Controllers\Bank;

use App\Enums\BankChannel;
use App\Enums\BankReviewStatus;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BankPresenter;
use App\Http\Requests\Bank\CatalogBankTransactionRequest;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Services\Bank\BankCatalogService;
use App\Services\Bank\BankOffsetService;
use App\Services\Bank\BankRuleBook;
use App\Support\Month;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The inbox of transactions imported from the member's banks, waiting to be
 * catalogued, with what the rules suggest for each one.
 */
class BankInboxController extends Controller
{
    use InteractsWithHousehold;

    public function index(Request $request, BankCatalogService $catalog, BankRuleBook $rules, BankOffsetService $offsets, BankPresenter $presenter): Response
    {
        $user = $this->user($request);
        $filters = $this->filters($request);

        $query = $this->filteredQuery($user->id, $filters);

        // Suggestions are worked out in PHP, so the filter narrows the query
        // down to the transactions that have (or lack) one.
        if ($filters['suggestion'] !== null) {
            $query->whereIntegerInRaw('id', $catalog->idsBySuggestion($query, $user, $filters['suggestion'] === 'with'));
        }

        $transactions = $query
            ->with(['account', 'bill', 'expenseOccurrence', 'personalTransaction', 'offsetTransaction'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $items = $transactions->getCollection();
        $items->each(fn (BankTransaction $transaction) => $transaction->setRelation('user', $user));

        $userRules = $rules->rulesOf($user->id);
        $candidates = $catalog->candidates($items, $user);
        $offsetCandidates = $offsets->candidates($items);

        // Only needed to catalogue, i.e. when money going out is pending.
        // (Checked before through(), which turns the items into arrays.)
        $linkable = $items->contains(fn (BankTransaction $transaction) => $transaction->isPending() && $transaction->isOutgoing())
            ? $catalog->linkable($user, $items)->map(fn ($occurrence) => $presenter->candidate($occurrence))->values()->all()
            : [];

        return Inertia::render('bank/inbox', [
            'filters' => $filters,
            'accounts' => BankAccount::query()
                ->where('user_id', $user->id)
                ->orderBy('type')
                ->orderBy('name')
                ->get(['id', 'name', 'type'])
                ->map(fn (BankAccount $account) => ['id' => $account->id, 'name' => $account->name, 'type' => $account->type->value])
                ->all(),
            'transactions' => $transactions->through(fn (BankTransaction $transaction) => $presenter->transaction(
                $transaction,
                $catalog->referenceDate($transaction),
                $catalog->suggest($transaction, $userRules, $candidates[$transaction->id] ?? []),
                $candidates[$transaction->id] ?? [],
                $offsetCandidates[$transaction->id] ?? [],
            )),
            'counts' => [
                'pending' => $catalog->pendingOf($user)->count(),
                'removed' => BankTransaction::query()
                    ->where('user_id', $user->id)
                    ->whereNotNull('removed_at')
                    ->where('review_status', BankReviewStatus::Catalogued)
                    ->count(),
            ],
            'hasConnections' => BankAccount::query()->where('user_id', $user->id)->exists(),
            'linkable' => $linkable,
        ]);
    }

    public function catalog(CatalogBankTransactionRequest $request, BankTransaction $transaction, BankCatalogService $catalog): RedirectResponse
    {
        $choice = $request->toChoice();

        $catalog->catalog($transaction, $this->user($request), $choice);

        $this->toast(match ($choice->action) {
            'ignore' => 'Transação ignorada.',
            'link' => 'Transação vinculada à despesa.',
            'offset' => 'As duas transações foram compensadas.',
            default => 'Transação catalogada.',
        });

        return back();
    }

    /**
     * Apply what the inbox suggests for one transaction.
     */
    public function confirm(Request $request, BankTransaction $transaction, BankCatalogService $catalog): RedirectResponse
    {
        Gate::authorize('update', $transaction);

        $catalog->confirmSuggestion($transaction, $this->user($request));

        $this->toast('Sugestão confirmada.');

        return back();
    }

    /**
     * Apply every suggestion of the inbox at once.
     */
    public function confirmAll(Request $request, BankCatalogService $catalog): RedirectResponse
    {
        $count = $catalog->confirmAll($this->user($request));

        $this->toast(match ($count) {
            0 => 'Nenhuma sugestão para confirmar.',
            1 => '1 transação catalogada.',
            default => "{$count} transações catalogadas.",
        }, $count === 0 ? 'info' : 'success');

        return back();
    }

    /**
     * Undo: put the transaction back in the inbox.
     */
    public function reopen(Request $request, BankTransaction $transaction, BankCatalogService $catalog): RedirectResponse
    {
        Gate::authorize('update', $transaction);

        $catalog->reopen($transaction, $this->user($request));

        $this->toast('Transação de volta à caixa de entrada.');

        return back();
    }

    /**
     * Suggestions exist only for pending transactions, so the suggestion
     * filter applies to that tab alone.
     *
     * @return array{status: string, account: ?int, channel: ?string, month: ?string, suggestion: ?string}
     */
    private function filters(Request $request): array
    {
        $status = $request->query('status');
        $status = in_array($status, ['pending', 'catalogued', 'ignored', 'removed', 'all'], true) ? $status : 'pending';
        $channel = $request->query('channel');
        $month = $request->query('month');
        $suggestion = $request->query('suggestion');

        return [
            'status' => $status,
            'account' => $request->integer('account') ?: null,
            'channel' => in_array($channel, ['pix', 'card', 'transfer', 'other'], true) ? $channel : null,
            'month' => is_string($month) && preg_match(Month::PATTERN, $month) === 1 ? $month : null,
            'suggestion' => $status === 'pending' && in_array($suggestion, ['with', 'without'], true) ? $suggestion : null,
        ];
    }

    /**
     * @param  array{status: string, account: ?int, channel: ?string, month: ?string, suggestion: ?string}  $filters
     * @return Builder<BankTransaction>
     */
    private function filteredQuery(int $userId, array $filters): Builder
    {
        $query = BankTransaction::query()->where('user_id', $userId);

        match ($filters['status']) {
            'pending' => $query->where('review_status', BankReviewStatus::Pending)->whereNull('removed_at'),
            'catalogued' => $query->where('review_status', BankReviewStatus::Catalogued),
            'ignored' => $query->where('review_status', BankReviewStatus::Ignored),
            'removed' => $query->whereNotNull('removed_at'),
            default => null,
        };

        $query->when($filters['account'], fn (Builder $query, int $account) => $query->where('bank_account_id', $account));

        match ($filters['channel']) {
            'pix' => $query->where('channel', BankChannel::Pix),
            'card' => $query->whereIn('channel', [BankChannel::Card, BankChannel::Fee])->whereHas('account', fn (Builder $query) => $query->where('type', 'CREDIT')),
            'transfer' => $query->whereIn('channel', [BankChannel::Transfer, BankChannel::Boleto]),
            'other' => $query->whereNotIn('channel', [BankChannel::Pix, BankChannel::Card, BankChannel::Transfer, BankChannel::Boleto]),
            default => null,
        };

        if ($filters['month'] !== null) {
            $month = Month::parse($filters['month']);
            $query->whereBetween('date', [$month->toDateString(), $month->endOfMonth()->toDateString()]);
        }

        return $query;
    }
}
