<?php

namespace App\Services\Bank;

use App\Enums\BankCatalogType;
use App\Enums\BankChannel;
use App\Enums\BankReviewStatus;
use App\Enums\BankRuleAction;
use App\Enums\ExpenseScope;
use App\Enums\OccurrenceStatus;
use App\Enums\TransactionType;
use App\Models\BankBill;
use App\Models\BankRule;
use App\Models\BankTransaction;
use App\Models\ExpenseOccurrence;
use App\Models\PersonalTransaction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ExpenseService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The inbox of imported transactions: catalogues each one as a household or
 * personal expense, links it to an expense that already exists (this
 * month's rent, an installment typed by hand), records it as income or an
 * investment, or ignores it, and undoes any of that. Every decision can be
 * remembered as a rule that suggests the same for the next ones.
 *
 * Payment confirmation keeps its rule: linking marks the expense as paid
 * only when the policy lets the owner confirm it; a household expense the
 * owner created waits for the other member, who sees the bank proof.
 */
final class BankCatalogService
{
    /**
     * Days around the transaction's reference date where expenses are
     * offered for linking.
     */
    private const LINK_WINDOW_DAYS = 45;

    /**
     * Expenses offered for linking per transaction.
     */
    private const MAX_CANDIDATES = 6;

    /**
     * Closed bills per card account, loaded once per request.
     *
     * @var array<int, Collection<int, BankBill>>
     */
    private array $bills = [];

    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly PaymentService $payments,
        private readonly BankRuleBook $rules,
        private readonly AuditLogger $audit,
    ) {
        //
    }

    /**
     * Handle a transaction just imported: bill payments are ignored (their
     * purchases come through the card), a transaction the bank re-created
     * with a new id keeps what was decided for the old one, and rules set to
     * apply automatically catalogue it.
     */
    public function onImported(BankTransaction $transaction): void
    {
        if ($transaction->channel === BankChannel::BillPayment) {
            $transaction->update([
                'review_status' => BankReviewStatus::Ignored,
                'ignore_reason' => BankTransaction::IGNORED_BILL_PAYMENT,
                'reviewed_at' => Date::now(),
            ]);

            return;
        }

        if ($this->inheritFromReplaced($transaction)) {
            return;
        }

        $rule = $this->rules->match($transaction);

        if ($rule === null || ! $rule->auto_apply) {
            return;
        }

        $suggestion = $this->suggestionFromRule($transaction, $rule);

        if ($suggestion === null) {
            return;
        }

        try {
            $this->catalog($transaction, $transaction->user, $suggestion->choice);
            $this->rules->used($rule);
        } catch (ValidationException) {
            // Stays in the inbox with the suggestion.
        }
    }

    /**
     * Catalogue a pending transaction of the actor.
     */
    public function catalog(BankTransaction $transaction, User $actor, CatalogChoice $choice): BankTransaction
    {
        return DB::transaction(function () use ($transaction, $actor, $choice) {
            $transaction = BankTransaction::query()->with(['account', 'bill'])->lockForUpdate()->findOrFail($transaction->id);
            $this->ensureOwner($transaction, $actor);

            if (! $transaction->isPending()) {
                throw ValidationException::withMessages(['transaction' => 'Esta transação já foi catalogada. Desfaça antes de catalogar de novo.']);
            }

            $this->ensureFits($transaction, $choice->action);

            $title = $this->title($choice->title, $transaction);
            $date = $choice->date ?? $this->referenceDate($transaction);
            $now = Date::now();
            $ruleAction = null;
            $recurringExpenseId = null;

            $attributes = ['review_status' => BankReviewStatus::Catalogued, 'ignore_reason' => null, 'reviewed_at' => $now];

            switch ($choice->action) {
                case 'household':
                    $occurrence = $this->expenses->create($actor->household(), $actor, [
                        'scope' => ExpenseScope::Household,
                        'title' => $title,
                        'notes' => $choice->notes,
                        'amount_cents' => $transaction->amount_cents,
                        'due_date' => $date,
                        'installment_number' => $transaction->installment_number,
                        'installment_count' => $transaction->installment_count,
                    ]);
                    $attributes += ['catalog_type' => BankCatalogType::Household, 'expense_occurrence_id' => $occurrence->id];
                    $ruleAction = BankRuleAction::Household;
                    break;

                case 'personal':
                case 'income':
                case 'investment':
                    $type = match ($choice->action) {
                        'personal' => TransactionType::Expense,
                        'income' => TransactionType::Income,
                        default => TransactionType::Investment,
                    };

                    $entry = PersonalTransaction::create([
                        'user_id' => $actor->id,
                        'type' => $type,
                        'title' => $title,
                        'amount_cents' => $transaction->amount_cents,
                        'transaction_date' => $date,
                        'notes' => $choice->notes,
                    ]);

                    $attributes += [
                        'catalog_type' => BankCatalogType::from($choice->action),
                        'personal_transaction_id' => $entry->id,
                    ];
                    $ruleAction = BankRuleAction::from($choice->action);
                    break;

                case 'link':
                    $occurrence = $this->link($transaction, $actor, $choice->occurrenceId);
                    $attributes += ['catalog_type' => BankCatalogType::Linked, 'expense_occurrence_id' => $occurrence->id];

                    if ($occurrence->recurring_expense_id !== null) {
                        $ruleAction = BankRuleAction::Recurring;
                        $recurringExpenseId = $occurrence->recurring_expense_id;
                    }
                    break;

                default:
                    $attributes = [
                        'review_status' => BankReviewStatus::Ignored,
                        'ignore_reason' => BankTransaction::IGNORED_BY_USER,
                        'reviewed_at' => $now,
                    ];
                    $ruleAction = BankRuleAction::Ignore;
            }

            $transaction->update($attributes);

            $this->audit->record($choice->action === 'ignore' ? 'bank.ignored' : 'bank.catalogued', $transaction, $actor, $actor->householdId(), null, [
                'action' => $choice->action,
                'amount_cents' => $transaction->amount_cents,
                'expense_occurrence_id' => $transaction->expense_occurrence_id,
                'personal_transaction_id' => $transaction->personal_transaction_id,
            ]);

            if ($choice->remember && $ruleAction !== null) {
                $this->rules->learn($actor, $transaction, $ruleAction, $title, $recurringExpenseId, $choice->autoApply, $choice->rememberAmount);
            }

            return $transaction;
        });
    }

    /**
     * Apply what the inbox suggests for a transaction.
     */
    public function confirmSuggestion(BankTransaction $transaction, User $actor): BankTransaction
    {
        $this->ensureOwner($transaction, $actor);

        $suggestion = $this->suggest($transaction);

        if ($suggestion === null) {
            throw ValidationException::withMessages(['transaction' => 'Não há sugestão para esta transação. Catalogue-a manualmente.']);
        }

        return DB::transaction(function () use ($transaction, $actor, $suggestion) {
            $transaction = $this->catalog($transaction, $actor, $suggestion->choice);

            if ($suggestion->rule !== null) {
                $this->rules->used($suggestion->rule);
            }

            return $transaction;
        });
    }

    /**
     * Apply every suggestion of the actor's inbox; transactions whose
     * suggestion can no longer be applied stay pending.
     *
     * @return int How many were catalogued.
     */
    public function confirmAll(User $actor): int
    {
        $transactions = $this->pendingOf($actor)->with(['account', 'bill', 'user'])->orderBy('date')->limit(200)->get();
        $rules = $this->rules->rulesOf($actor->id);
        $candidates = $this->candidates($transactions, $actor);
        $count = 0;

        foreach ($transactions as $transaction) {
            $suggestion = $this->suggest($transaction, $rules, $candidates[$transaction->id] ?? []);

            if ($suggestion === null) {
                continue;
            }

            try {
                DB::transaction(function () use ($transaction, $actor, $suggestion) {
                    $this->catalog($transaction, $actor, $suggestion->choice);

                    if ($suggestion->rule !== null) {
                        $this->rules->used($suggestion->rule);
                    }
                });
                $count++;
            } catch (ValidationException) {
                // A suggested expense was taken in the meantime: leave it.
            }
        }

        return $count;
    }

    /**
     * Put a catalogued or ignored transaction back in the inbox, removing the
     * expense or personal entry it created. A linked expense is only
     * unlinked: its amount and payment stay as they are.
     */
    public function reopen(BankTransaction $transaction, User $actor): void
    {
        DB::transaction(function () use ($transaction, $actor) {
            $transaction = BankTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $this->ensureOwner($transaction, $actor);

            if ($transaction->isPending()) {
                throw ValidationException::withMessages(['transaction' => 'Esta transação já está na caixa de entrada.']);
            }

            $old = [
                'review_status' => $transaction->review_status->value,
                'catalog_type' => $transaction->catalog_type?->value,
                'expense_occurrence_id' => $transaction->expense_occurrence_id,
                'personal_transaction_id' => $transaction->personal_transaction_id,
            ];

            if ($transaction->catalog_type === BankCatalogType::Household && $transaction->expense_occurrence_id !== null) {
                $occurrence = ExpenseOccurrence::query()->find($transaction->expense_occurrence_id);

                if ($occurrence !== null) {
                    if ($occurrence->isPaid()) {
                        throw ValidationException::withMessages(['transaction' => 'A despesa criada já foi paga. Reabra o pagamento em Despesas antes de desfazer.']);
                    }

                    $this->expenses->delete($occurrence, $actor);
                }
            }

            if ($transaction->personal_transaction_id !== null) {
                PersonalTransaction::query()->where('user_id', $actor->id)->whereKey($transaction->personal_transaction_id)->delete();
            }

            $transaction->update([
                'review_status' => BankReviewStatus::Pending,
                'catalog_type' => null,
                'ignore_reason' => null,
                'expense_occurrence_id' => null,
                'personal_transaction_id' => null,
                'reviewed_at' => null,
            ]);

            $this->audit->record('bank.reopened', $transaction, $actor, $actor->householdId(), $old);
        });
    }

    /**
     * What the inbox proposes for a pending transaction: its rule, or else
     * an expense that clearly matches it.
     *
     * @param  Collection<int, BankRule>|null  $rules  The owner's rules, when already loaded.
     * @param  list<array{occurrence: ExpenseOccurrence, score: int, strong: bool}>|null  $candidates
     */
    public function suggest(BankTransaction $transaction, ?Collection $rules = null, ?array $candidates = null): ?BankSuggestion
    {
        if (! $transaction->isPending() || $transaction->removed_at !== null) {
            return null;
        }

        $rule = $this->rules->match($transaction, $rules);

        if ($rule !== null && ($suggestion = $this->suggestionFromRule($transaction, $rule)) !== null) {
            return $suggestion;
        }

        $candidates ??= $this->candidates(collect([$transaction]), $transaction->user)[$transaction->id] ?? [];
        $best = $candidates[0] ?? null;

        if ($best === null || ! $best['strong']) {
            return null;
        }

        return new BankSuggestion(
            new CatalogChoice('link', occurrenceId: $best['occurrence']->id),
            'Vincular a “'.$best['occurrence']->title.'”'.$this->installmentLabel($best['occurrence']),
            null,
            $best['occurrence'],
        );
    }

    public function suggestionFromRule(BankTransaction $transaction, BankRule $rule): ?BankSuggestion
    {
        if ($rule->action === BankRuleAction::Recurring) {
            $occurrence = $this->recurringOccurrence($transaction, $rule);

            if ($occurrence === null) {
                return null;
            }

            return new BankSuggestion(
                new CatalogChoice('link', occurrenceId: $occurrence->id, remember: false),
                '“'.$occurrence->title.'” de '.$this->monthLabel($occurrence->billing_month),
                $rule,
                $occurrence,
            );
        }

        return new BankSuggestion(
            new CatalogChoice($rule->action->value, title: $rule->title, remember: false),
            $rule->action->label().($rule->title !== null ? ': '.$rule->title : ''),
            $rule,
        );
    }

    /**
     * Pending expenses the actor may link to each pending outgoing
     * transaction, best first: same amount (or a month still without amount),
     * close to the reference date, same installment, similar title. Expenses
     * already linked to another transaction are left out.
     *
     * @param  Collection<int, BankTransaction>  $transactions
     * @return array<int, list<array{occurrence: ExpenseOccurrence, score: int, strong: bool}>>
     */
    public function candidates(Collection $transactions, User $user): array
    {
        $pending = $transactions->filter(fn (BankTransaction $transaction) => $transaction->isPending() && $transaction->isOutgoing());

        if ($pending->isEmpty()) {
            return [];
        }

        $references = $pending->mapWithKeys(fn (BankTransaction $transaction) => [$transaction->id => $this->referenceDate($transaction)]);

        /** @var CarbonImmutable $from */
        $from = $references->min();
        /** @var CarbonImmutable $to */
        $to = $references->max();

        $occurrences = ExpenseOccurrence::query()
            ->visibleTo($user)
            ->where('status', OccurrenceStatus::Pending)
            ->whereBetween('due_date', [$from->subDays(self::LINK_WINDOW_DAYS)->toDateString(), $to->addDays(self::LINK_WINDOW_DAYS)->toDateString()])
            ->whereDoesntHave('bankTransactions', fn (Builder $query) => $query->whereNull('removed_at'))
            ->orderBy('due_date')
            ->limit(500)
            ->get();

        $result = [];

        foreach ($pending as $transaction) {
            $reference = $references[$transaction->id];
            $scored = [];

            foreach ($occurrences as $occurrence) {
                $days = abs((int) $occurrence->due_date->diffInDays($reference));

                if ($days > self::LINK_WINDOW_DAYS) {
                    continue;
                }

                $sameAmount = $occurrence->amount_cents === $transaction->amount_cents;
                // A month still without amount (a variable bill) fits a payment
                // close to its due date, never a card installment.
                $amountScore = match (true) {
                    $sameAmount => 50,
                    $occurrence->amount_cents === 0 => $days <= 20 && $transaction->installment_count === null ? 30 : 0,
                    abs($occurrence->amount_cents - $transaction->amount_cents) * 10 <= $transaction->amount_cents => 10,
                    default => 0,
                };
                $sameInstallment = $transaction->installment_count !== null
                    && $occurrence->installment_count === $transaction->installment_count
                    && $occurrence->installment_number === $transaction->installment_number;
                $similarTitle = $this->similar($occurrence->title, $transaction->match_key);

                if ($amountScore === 0 && ! $similarTitle) {
                    continue;
                }

                $score = $amountScore
                    + max(0, 20 - intdiv($days, 2))
                    + ($sameInstallment ? 30 : 0)
                    + ($similarTitle ? 20 : 0);

                $scored[] = [
                    'occurrence' => $occurrence,
                    'score' => $score,
                    'strong' => $sameAmount && ($sameInstallment || $similarTitle || $days <= 5),
                ];
            }

            usort($scored, fn (array $a, array $b) => $b['score'] <=> $a['score']);
            $result[$transaction->id] = array_slice($scored, 0, self::MAX_CANDIDATES);
        }

        return $result;
    }

    /**
     * Every pending expense the user may link a transaction to — household
     * ones and their own personal ones, not linked to another transaction —
     * from a year back to three months ahead, for picking by hand when the
     * suggestions miss (a PIX to a person that pays a household bill).
     *
     * @return Collection<int, ExpenseOccurrence>
     */
    public function linkable(User $user): Collection
    {
        $today = Date::today();

        return ExpenseOccurrence::query()
            ->visibleTo($user)
            ->where('status', OccurrenceStatus::Pending)
            ->whereBetween('due_date', [$today->subYear()->toDateString(), $today->addMonths(3)->toDateString()])
            ->whereDoesntHave('bankTransactions', fn (Builder $query) => $query->whereNull('removed_at'))
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(500)
            ->get();
    }

    /**
     * The date the transaction counts on: for a card purchase the due date
     * of its bill (the month the money actually leaves), for anything else
     * the day it happened. The bill comes from the transaction itself or, for
     * purchases of the open bill, from the closing dates of the last bills.
     */
    public function referenceDate(BankTransaction $transaction): CarbonImmutable
    {
        $account = $transaction->account;

        if (! $account->isCreditCard()) {
            return $transaction->date;
        }

        if ($transaction->bill !== null) {
            return $transaction->bill->due_date;
        }

        $bills = $this->bills[$account->id] ??= $account->bills()->whereNotNull('closing_date')->orderBy('closing_date')->get();
        $date = $transaction->date;

        $closedIn = $bills->first(fn (BankBill $bill) => $bill->closing_date !== null && $bill->closing_date->gte($date));

        if ($closedIn !== null) {
            return $closedIn->due_date;
        }

        $last = $bills->last();

        if ($last !== null && $last->closing_date !== null) {
            $closing = $last->closing_date;
            $due = $last->due_date;

            for ($month = 0; $month < 24 && $date->gt($closing); $month++) {
                $closing = $closing->addMonthsNoOverflow(1);
                $due = $due->addMonthsNoOverflow(1);
            }

            return $due;
        }

        $due = $account->balance_due_date;

        return $due !== null && $due->gte($date) && $due->lte($date->addDays(60)) ? $due : $date;
    }

    /**
     * @return Builder<BankTransaction>
     */
    public function pendingOf(User $user): Builder
    {
        return BankTransaction::query()
            ->where('user_id', $user->id)
            ->where('review_status', BankReviewStatus::Pending)
            ->whereNull('removed_at');
    }

    /**
     * Link to an expense the actor can see, setting its amount to what the
     * bank shows (variable bills get their real value) and confirming the
     * payment when the actor is allowed to.
     */
    private function link(BankTransaction $transaction, User $actor, ?int $occurrenceId): ExpenseOccurrence
    {
        $occurrence = $occurrenceId === null ? null : ExpenseOccurrence::query()->visibleTo($actor)->find($occurrenceId);

        if ($occurrence === null) {
            throw ValidationException::withMessages(['occurrence_id' => 'Escolha uma despesa da casa ou sua.']);
        }

        $taken = BankTransaction::query()
            ->where('expense_occurrence_id', $occurrence->id)
            ->whereKeyNot($transaction->id)
            ->whereNull('removed_at')
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['occurrence_id' => 'Esta despesa já está vinculada a outra transação do banco.']);
        }

        if ($occurrence->isPaid()) {
            return $occurrence;
        }

        if ($occurrence->amount_cents !== $transaction->amount_cents) {
            $occurrence = $this->expenses->update($occurrence, $actor, [
                'title' => $occurrence->title,
                'notes' => $occurrence->notes,
                'amount_cents' => $transaction->amount_cents,
                'due_date' => $occurrence->due_date,
            ]);
        }

        if ($actor->can('confirmPayment', $occurrence)) {
            $occurrence = $this->payments->confirm($occurrence, $actor, $transaction->date->setTime(12, 0));
        }

        return $occurrence;
    }

    /**
     * The month of the rule's recurring expense closest to the transaction,
     * still pending and not linked to another transaction.
     */
    private function recurringOccurrence(BankTransaction $transaction, BankRule $rule): ?ExpenseOccurrence
    {
        if ($rule->recurring_expense_id === null) {
            return null;
        }

        $reference = $this->referenceDate($transaction);

        return ExpenseOccurrence::query()
            ->visibleTo($transaction->user)
            ->where('recurring_expense_id', $rule->recurring_expense_id)
            ->where('status', OccurrenceStatus::Pending)
            ->whereBetween('due_date', [$reference->subDays(self::LINK_WINDOW_DAYS)->toDateString(), $reference->addDays(self::LINK_WINDOW_DAYS)->toDateString()])
            ->whereDoesntHave('bankTransactions', fn (Builder $query) => $query->whereNull('removed_at')->whereKeyNot($transaction->id))
            ->get()
            ->sortBy(fn (ExpenseOccurrence $occurrence) => abs((int) $occurrence->due_date->diffInDays($reference)))
            ->first();
    }

    /**
     * A transaction the bank deleted and re-created with a new id (common
     * for card purchases around the bill closing) keeps what was decided for
     * the old one, which is then dropped.
     */
    private function inheritFromReplaced(BankTransaction $transaction): bool
    {
        $replaced = BankTransaction::query()
            ->where('bank_account_id', $transaction->bank_account_id)
            ->whereKeyNot($transaction->id)
            ->whereNotNull('removed_at')
            ->where('review_status', '!=', BankReviewStatus::Pending)
            ->where('direction', $transaction->direction)
            ->where('amount_cents', $transaction->amount_cents)
            ->where('match_key', $transaction->match_key)
            ->where('installment_number', $transaction->installment_number)
            ->whereBetween('date', [$transaction->date->subDays(7)->toDateString(), $transaction->date->addDays(7)->toDateString()])
            ->orderBy('id')
            ->first();

        if ($replaced === null) {
            return false;
        }

        DB::transaction(function () use ($transaction, $replaced) {
            $transaction->update([
                'review_status' => $replaced->review_status,
                'catalog_type' => $replaced->catalog_type,
                'ignore_reason' => $replaced->ignore_reason,
                'expense_occurrence_id' => $replaced->expense_occurrence_id,
                'personal_transaction_id' => $replaced->personal_transaction_id,
                'reviewed_at' => $replaced->reviewed_at,
            ]);

            $replaced->delete();
        });

        return true;
    }

    private function ensureOwner(BankTransaction $transaction, User $actor): void
    {
        if ($transaction->user_id !== $actor->id) {
            abort(404);
        }
    }

    private function ensureFits(BankTransaction $transaction, string $action): void
    {
        if (! in_array($action, CatalogChoice::ACTIONS, true)) {
            throw ValidationException::withMessages(['action' => 'Escolha como catalogar a transação.']);
        }

        if ($transaction->amount_cents === 0 && $action !== 'ignore') {
            throw ValidationException::withMessages(['action' => 'Transações sem valor só podem ser ignoradas.']);
        }

        $fits = match ($action) {
            'income' => ! $transaction->isOutgoing(),
            'ignore' => true,
            default => $transaction->isOutgoing(),
        };

        if (! $fits) {
            throw ValidationException::withMessages([
                'action' => $transaction->isOutgoing()
                    ? 'Saídas de dinheiro não podem ser receitas.'
                    : 'Entradas de dinheiro só podem ser receitas ou ignoradas.',
            ]);
        }
    }

    private function title(?string $title, BankTransaction $transaction): string
    {
        $title = trim((string) $title);

        return Str::limit($title !== '' ? $title : $transaction->displayName(), 120, '');
    }

    /**
     * Whether an expense title and a transaction key name the same thing
     * ("Aluguel" and "pix aluguel fulano").
     */
    private function similar(string $title, string $key): bool
    {
        $title = TransactionClassifier::normalize($title);

        return strlen($title) >= 4 && strlen($key) >= 4 && (str_contains($key, $title) || str_contains($title, $key));
    }

    /**
     * "novembro/2026".
     */
    private function monthLabel(CarbonImmutable $month): string
    {
        $names = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

        return $names[$month->month - 1].'/'.$month->year;
    }

    private function installmentLabel(ExpenseOccurrence $occurrence): string
    {
        return $occurrence->installment_number !== null ? " (parcela {$occurrence->installment_number}/{$occurrence->installment_count})" : '';
    }
}
