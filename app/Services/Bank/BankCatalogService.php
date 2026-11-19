<?php

namespace App\Services\Bank;

use App\Enums\BankAccountType;
use App\Enums\BankCatalogType;
use App\Enums\BankChannel;
use App\Enums\BankReviewStatus;
use App\Enums\BankRuleAction;
use App\Enums\ExpenseScope;
use App\Enums\OccurrenceSource;
use App\Enums\OccurrenceStatus;
use App\Enums\TransactionType;
use App\Models\BankRule;
use App\Models\BankSettlementItem;
use App\Models\BankSplitItem;
use App\Models\BankTransaction;
use App\Models\ExpenseOccurrence;
use App\Models\PersonalTransaction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ExpenseService;
use App\Services\PaymentService;
use App\Support\Money;
use App\Support\Month;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
 * investment, splits its value into parts catalogued on their own (part an
 * existing expense, part a personal one), or ignores it, and undoes any of
 * that. Every decision but a split can be remembered as a rule that
 * suggests the same for the next ones.
 *
 * Payment confirmation keeps its rule: linking marks the expense as paid
 * only when the policy lets the owner confirm it; a household expense the
 * owner created waits for the other member, who sees the bank proof. The
 * exception is the house settlement (see {@see BankSettlementService}): a
 * transfer between the members pays their shares of the chosen expenses.
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
     * Months around the inbox page's transactions where expenses already
     * paid are listed for linking by hand.
     */
    private const PAID_LINK_MONTHS = 3;

    /**
     * Expenses loaded at most to work out the suggestions of a batch of
     * transactions (paid ones count too, so a year of history fits).
     */
    private const MAX_LOADED_OCCURRENCES = 2000;

    /**
     * Pending transactions looked at when filtering the inbox by suggestion
     * (the newest ones), since each suggestion is worked out one by one.
     */
    private const SUGGESTION_FILTER_LIMIT = 500;

    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly PaymentService $payments,
        private readonly BankRuleBook $rules,
        private readonly BankOffsetService $offsets,
        private readonly CardBillCalendar $bills,
        private readonly BankSettlementService $settlements,
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

        // Before the rules: an automatic rule must not catalogue a charge
        // its refund is about to cancel out.
        if ($this->offsets->autoPair($transaction)) {
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
        if ($choice->action === 'offset') {
            return $this->offset($transaction, $actor, $choice->offsetTransactionId);
        }

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
            $settled = null;
            $parts = null;

            $attributes = ['review_status' => BankReviewStatus::Catalogued, 'ignore_reason' => null, 'reviewed_at' => $now];

            switch ($choice->action) {
                case 'household':
                    $occurrence = $this->createHouseholdExpense($transaction, $actor, $title, $choice->notes, $transaction->amount_cents, $date);
                    $attributes += ['catalog_type' => BankCatalogType::Household, 'expense_occurrence_id' => $occurrence->id];
                    $ruleAction = BankRuleAction::Household;
                    break;

                case 'personal':
                case 'income':
                case 'investment':
                    $entry = $this->createEntry($actor, $choice->action, $title, $transaction->amount_cents, $date, $choice->notes);

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

                case 'settlement':
                    $settled = $this->settlements->settle($transaction, $actor, $choice->occurrenceIds);
                    $attributes += ['catalog_type' => BankCatalogType::Settlement];
                    $ruleAction = BankRuleAction::Settlement;
                    break;

                case 'split':
                    $parts = $this->split($transaction, $actor, $choice, $date);
                    $attributes += ['catalog_type' => BankCatalogType::Split];
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
            ] + ($settled !== null ? ['settlement' => $settled + ['occurrence_ids' => $choice->occurrenceIds]] : [])
              + ($parts !== null ? ['parts' => $parts] : []));

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
     * expense or personal entry it created (each part's, for a split one). A
     * linked expense is only unlinked: its amount and payment stay as they
     * are.
     */
    public function reopen(BankTransaction $transaction, User $actor): void
    {
        DB::transaction(function () use ($transaction, $actor) {
            $transaction = BankTransaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $this->ensureOwner($transaction, $actor);

            if ($transaction->isPending()) {
                throw ValidationException::withMessages(['transaction' => 'Esta transação já está na caixa de entrada.']);
            }

            // A pair that cancels out is undone as a whole.
            if ($transaction->isOffset()) {
                $partner = $this->offsets->unpair($transaction);

                foreach (array_filter([$transaction, $partner]) as $side) {
                    $this->audit->record('bank.reopened', $side, $actor, $actor->householdId(), [
                        'review_status' => BankReviewStatus::Ignored->value,
                        'ignore_reason' => BankTransaction::IGNORED_OFFSET,
                    ]);
                }

                return;
            }

            $old = [
                'review_status' => $transaction->review_status->value,
                'catalog_type' => $transaction->catalog_type?->value,
                'expense_occurrence_id' => $transaction->expense_occurrence_id,
                'personal_transaction_id' => $transaction->personal_transaction_id,
            ];

            // A settlement's expenses become pending again.
            if ($transaction->catalog_type === BankCatalogType::Settlement) {
                $this->settlements->undo($transaction, $actor);
            }

            if ($transaction->catalog_type === BankCatalogType::Split) {
                $old['parts'] = $this->undoSplit($transaction, $actor);
            }

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

        $offset = $this->offsets->find($transaction);

        if ($offset !== null) {
            $other = $offset['transaction'];
            $when = $other->date->format('d/m');

            return new BankSuggestion(
                new CatalogChoice('offset', remember: false, offsetTransactionId: $other->id),
                $other->isPending()
                    ? 'Anula “'.$other->displayName().'” de '.$when.': compensar as duas'
                    : 'Estorno de “'.$other->displayName().'” de '.$when.' (já catalogada): desfazer e compensar',
            );
        }

        $rule = $this->rules->match($transaction, $rules);

        if ($rule !== null && ($suggestion = $this->suggestionFromRule($transaction, $rule)) !== null) {
            return $suggestion;
        }

        $candidates ??= $this->candidates(collect([$transaction]), $transaction->user)[$transaction->id] ?? [];
        $best = $candidates[0] ?? null;

        if ($best === null || ! $best['strong']) {
            // A PIX between the members whose value is exactly what one owes
            // the other for a month: the house settlement.
            return in_array($transaction->channel, [BankChannel::Pix, BankChannel::Transfer], true)
                ? $this->settlementSuggestion($transaction)
                : null;
        }

        return new BankSuggestion(
            new CatalogChoice('link', occurrenceId: $best['occurrence']->id),
            'Vincular a “'.$best['occurrence']->title.'”'.$this->installmentLabel($best['occurrence']).$this->paidLabel($best['occurrence']),
            null,
            $best['occurrence'],
        );
    }

    /**
     * The pending transactions of the query the inbox has a suggestion for
     * (or, with $suggested false, has none for), to filter the inbox by it.
     * Only the newest SUGGESTION_FILTER_LIMIT are looked at.
     *
     * @param  Builder<BankTransaction>  $query  The owner's transactions, already filtered.
     * @return array<int, int>
     */
    public function idsBySuggestion(Builder $query, User $user, bool $suggested): array
    {
        $transactions = (clone $query)
            ->where('review_status', BankReviewStatus::Pending)
            ->whereNull('removed_at')
            ->with(['account', 'bill'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(self::SUGGESTION_FILTER_LIMIT)
            ->get();
        $transactions->each(fn (BankTransaction $transaction) => $transaction->setRelation('user', $user));

        $rules = $this->rules->rulesOf($user->id);
        $candidates = $this->candidates($transactions, $user);

        return $transactions
            ->filter(fn (BankTransaction $transaction) => $suggested === ($this->suggest($transaction, $rules, $candidates[$transaction->id] ?? []) !== null))
            ->map(fn (BankTransaction $transaction) => $transaction->id)
            ->all();
    }

    public function suggestionFromRule(BankTransaction $transaction, BankRule $rule): ?BankSuggestion
    {
        if ($rule->action === BankRuleAction::Settlement) {
            return $this->settlementSuggestion($transaction, $rule);
        }

        if ($rule->action === BankRuleAction::Recurring) {
            $occurrence = $this->recurringOccurrence($transaction, $rule);

            if ($occurrence === null) {
                return null;
            }

            return new BankSuggestion(
                new CatalogChoice('link', occurrenceId: $occurrence->id, remember: false),
                '“'.$occurrence->title.'” de '.$this->monthLabel($occurrence->billing_month).$this->paidLabel($occurrence),
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
     * Expenses the actor may link to each pending outgoing transaction, best
     * first: same amount (or a month still without amount), due close to the
     * reference date or to the day it happened (a card purchase typed by hand
     * usually carries the purchase date), same installment, similar title.
     * Expenses already paid count too — history imported after the bills were
     * typed and paid by hand — and, on a tie, come after the pending ones.
     * Expenses already linked to another transaction (or to a part of a split
     * one) are left out.
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

        // Day numbers (days since the epoch), so the inner loop only compares integers.
        $days = [];
        $from = PHP_INT_MAX;
        $to = PHP_INT_MIN;

        foreach ($pending as $transaction) {
            $reference = $this->dayNumber($this->referenceDate($transaction));
            $happened = $this->dayNumber($transaction->date);
            $days[$transaction->id] = [$reference, $happened];
            $from = min($from, $reference, $happened);
            $to = max($to, $reference, $happened);
        }

        $occurrences = ExpenseOccurrence::query()
            ->visibleTo($user)
            ->whereBetween('due_date', [$this->dateOf($from - self::LINK_WINDOW_DAYS), $this->dateOf($to + self::LINK_WINDOW_DAYS)])
            ->withoutBankLink()
            ->orderBy('due_date')
            ->limit(self::MAX_LOADED_OCCURRENCES)
            ->get()
            ->map(fn (ExpenseOccurrence $occurrence) => [
                'occurrence' => $occurrence,
                'due' => $this->dayNumber($occurrence->due_date),
                'title' => TransactionClassifier::normalize($occurrence->title),
            ]);

        $result = [];

        foreach ($pending as $transaction) {
            [$reference, $happened] = $days[$transaction->id];
            $scored = [];

            foreach ($occurrences as ['occurrence' => $occurrence, 'due' => $due, 'title' => $title]) {
                $distance = min(abs($due - $reference), abs($due - $happened));

                if ($distance > self::LINK_WINDOW_DAYS) {
                    continue;
                }

                $sameAmount = $occurrence->amount_cents === $transaction->amount_cents;
                // A month still without amount (a variable bill) fits a payment
                // close to its due date, never a card installment.
                $amountScore = match (true) {
                    $sameAmount => 50,
                    $occurrence->awaitsAmount() => $distance <= 20 && $transaction->installment_count === null ? 30 : 0,
                    abs($occurrence->amount_cents - $transaction->amount_cents) * 10 <= $transaction->amount_cents => 10,
                    default => 0,
                };
                $sameInstallment = $transaction->installment_count !== null
                    && $occurrence->installment_count === $transaction->installment_count
                    && $occurrence->installment_number === $transaction->installment_number;
                $similarTitle = $this->similar($title, $transaction->match_key);

                if ($amountScore === 0 && ! $similarTitle) {
                    continue;
                }

                $score = $amountScore
                    + max(0, 20 - intdiv($distance, 2))
                    + ($sameInstallment ? 30 : 0)
                    + ($similarTitle ? 20 : 0);

                $scored[] = [
                    'occurrence' => $occurrence,
                    'score' => $score,
                    'strong' => $sameAmount && ($sameInstallment || $similarTitle || $distance <= 5),
                ];
            }

            usort($scored, fn (array $a, array $b) => [$b['score'], $a['occurrence']->isPaid()] <=> [$a['score'], $b['occurrence']->isPaid()]);
            $result[$transaction->id] = array_slice($scored, 0, self::MAX_CANDIDATES);
        }

        return $result;
    }

    /**
     * Every expense the user may pick by hand when the suggestions miss (a
     * PIX to a person that pays a household bill) — household ones and their
     * own personal ones, not linked to another transaction: pending ones from
     * a year back to three months ahead, and ones already paid due within
     * PAID_LINK_MONTHS of the given transactions (history imported after the
     * bills were paid by hand).
     *
     * @param  Collection<int, BankTransaction>  $transactions  The inbox page.
     * @return Collection<int, ExpenseOccurrence>
     */
    public function linkable(User $user, Collection $transactions): Collection
    {
        $today = Date::today();
        $dates = $transactions
            ->filter(fn (BankTransaction $transaction) => $transaction->isPending() && $transaction->isOutgoing())
            ->flatMap(fn (BankTransaction $transaction) => [$transaction->date, $this->referenceDate($transaction)]);

        /** @var CarbonImmutable|null $from */
        $from = $dates->min();
        /** @var CarbonImmutable|null $to */
        $to = $dates->max();

        return ExpenseOccurrence::query()
            ->visibleTo($user)
            ->where(function (Builder $query) use ($today, $from, $to) {
                $query->where(fn (Builder $query) => $query
                    ->where('status', OccurrenceStatus::Pending)
                    ->whereBetween('due_date', [$today->subYear()->toDateString(), $today->addMonths(3)->toDateString()]));

                if ($from !== null && $to !== null) {
                    $query->orWhere(fn (Builder $query) => $query
                        ->where('status', OccurrenceStatus::Paid)
                        ->whereBetween('due_date', [$from->subMonths(self::PAID_LINK_MONTHS)->toDateString(), $to->addMonths(self::PAID_LINK_MONTHS)->toDateString()]));
                }
            })
            ->withoutBankLink()
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(1000)
            ->get();
    }

    /**
     * The date the transaction counts on: for a card purchase the due date
     * of the bill that pays it (the month the money actually leaves; see
     * CardBillCalendar), for anything else the day it happened.
     */
    public function referenceDate(BankTransaction $transaction): CarbonImmutable
    {
        if (! $transaction->account->isCreditCard()) {
            return $transaction->date;
        }

        return $this->bills->dueDate($transaction) ?? $transaction->date;
    }

    /**
     * Move household expenses created from card purchases, still pending,
     * to the due date of the bill that pays them when they were left in an
     * earlier month (the bill used to be worked out from the purchase date
     * alone), so nothing shows as unpaid before the bill is even due. Only
     * moves them forward: a date set later by hand stays.
     *
     * @return int How many were moved.
     */
    public function moveCardExpensesToTheirBill(): int
    {
        $moved = 0;

        BankTransaction::query()
            ->where('catalog_type', BankCatalogType::Household)
            ->whereNotNull('expense_occurrence_id')
            ->whereHas('account', fn (Builder $query) => $query->where('type', BankAccountType::Credit))
            ->with(['account', 'bill', 'expenseOccurrence'])
            ->chunkById(200, function (Collection $transactions) use (&$moved) {
                foreach ($transactions as $transaction) {
                    $occurrence = $transaction->expenseOccurrence;

                    if ($occurrence === null || $occurrence->isPaid() || $occurrence->source !== OccurrenceSource::OneOff) {
                        continue;
                    }

                    $due = $this->referenceDate($transaction);

                    if (! $due->startOfMonth()->gt($occurrence->billing_month)) {
                        continue;
                    }

                    $old = ['billing_month' => Month::key($occurrence->billing_month), 'due_date' => $occurrence->due_date->toDateString()];
                    $occurrence->update(['due_date' => $due, 'billing_month' => $due->startOfMonth()]);

                    $this->audit->record('bank.moved_to_bill', $occurrence, null, $occurrence->household_id, $old, [
                        'billing_month' => Month::key($occurrence->billing_month),
                        'due_date' => $occurrence->due_date->toDateString(),
                        'bank_transaction_id' => $transaction->id,
                    ]);

                    $moved++;
                }
            });

        return $moved;
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
     * bank shows (variable bills get their real value) — or, for a part of
     * a split transaction, to the part's value — and confirming the payment
     * when the actor is allowed to. An expense already paid (history
     * imported after it was paid by hand) cannot change: it only gets the
     * bank proof, its amount and payment stay as they are.
     *
     * @param  string  $field  Where a validation error is reported.
     */
    private function link(BankTransaction $transaction, User $actor, ?int $occurrenceId, ?int $amountCents = null, string $field = 'occurrence_id'): ExpenseOccurrence
    {
        $amountCents ??= $transaction->amount_cents;
        $occurrence = $occurrenceId === null ? null : ExpenseOccurrence::query()->visibleTo($actor)->find($occurrenceId);

        if ($occurrence === null) {
            throw ValidationException::withMessages([$field => 'Escolha uma despesa da casa ou sua.']);
        }

        $taken = ExpenseOccurrence::query()->whereKey($occurrence->id)->withoutBankLink($transaction->id)->doesntExist();

        if ($taken) {
            throw ValidationException::withMessages([$field => "“{$occurrence->title}” já está vinculada a outra transação do banco."]);
        }

        if ($occurrence->isPaid()) {
            return $occurrence;
        }

        if ($occurrence->amount_cents !== $amountCents) {
            $occurrence = $this->expenses->update($occurrence, $actor, [
                'title' => $occurrence->title,
                'notes' => $occurrence->notes,
                'amount_cents' => $amountCents,
                'due_date' => $occurrence->due_date,
            ]);
        }

        if ($actor->can('confirmPayment', $occurrence)) {
            $occurrence = $this->payments->confirm($occurrence, $actor, $transaction->date->setTime(12, 0));
        }

        return $occurrence;
    }

    /**
     * The month of the rule's recurring expense closest to the transaction
     * and not linked to another transaction. A month already paid by hand
     * counts too (on a tie, after a pending one): its payment arriving now
     * from the bank must not take next month's place.
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
            ->whereBetween('due_date', [$reference->subDays(self::LINK_WINDOW_DAYS)->toDateString(), $reference->addDays(self::LINK_WINDOW_DAYS)->toDateString()])
            ->withoutBankLink($transaction->id)
            ->get()
            ->sortBy(fn (ExpenseOccurrence $occurrence) => [abs($this->dayNumber($occurrence->due_date) - $this->dayNumber($reference)), $occurrence->isPaid()])
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
                'offset_transaction_id' => $replaced->offset_transaction_id,
                'reviewed_at' => $replaced->reviewed_at,
            ]);

            BankSettlementItem::query()
                ->where('bank_transaction_id', $replaced->id)
                ->update(['bank_transaction_id' => $transaction->id]);

            BankSplitItem::query()
                ->where('bank_transaction_id', $replaced->id)
                ->update(['bank_transaction_id' => $transaction->id]);

            // The other side of a pair now points at the new transaction.
            if ($replaced->offset_transaction_id !== null) {
                BankTransaction::query()
                    ->whereKey($replaced->offset_transaction_id)
                    ->where('offset_transaction_id', $replaced->id)
                    ->update(['offset_transaction_id' => $transaction->id]);
            }

            $replaced->delete();
        });

        return true;
    }

    /**
     * Pair the transaction with one that cancels it out. A counterpart
     * already catalogued or ignored is first put back in the inbox (which
     * removes the expense it created, as long as it is not paid).
     */
    private function offset(BankTransaction $transaction, User $actor, ?int $otherId): BankTransaction
    {
        return DB::transaction(function () use ($transaction, $actor, $otherId) {
            $this->ensureOwner($transaction, $actor);

            $other = $otherId === null ? null : BankTransaction::query()->where('user_id', $actor->id)->find($otherId);

            if ($other === null) {
                throw ValidationException::withMessages(['offset_transaction_id' => 'Escolha a transação que anula esta.']);
            }

            if (! $transaction->fresh()?->isPending()) {
                throw ValidationException::withMessages(['transaction' => 'Esta transação já foi catalogada. Desfaça antes de catalogar de novo.']);
            }

            if (! $other->isPending() && ! $other->isOffset()) {
                $this->reopen($other, $actor);
            }

            $this->offsets->pair($transaction, $other, $actor);

            return $transaction->fresh() ?? $transaction;
        });
    }

    /**
     * Split the transaction's value into parts, each catalogued on its own:
     * a new household expense, a link to an expense that already exists, or
     * a personal expense or investment. The parts must add up to the
     * transaction, and an expense goes into one part only.
     *
     * @return list<array{type: string, amount_cents: int, expense_occurrence_id: int|null, personal_transaction_id: int|null}>
     */
    private function split(BankTransaction $transaction, User $actor, CatalogChoice $choice, CarbonImmutable $date): array
    {
        $parts = $choice->parts;

        if (count($parts) < 2 || count($parts) > CatalogChoice::MAX_PARTS) {
            throw ValidationException::withMessages(['parts' => 'Divida o valor em 2 a '.CatalogChoice::MAX_PARTS.' partes.']);
        }

        foreach ($parts as $index => $part) {
            if (! in_array($part->action, SplitPart::ACTIONS, true)) {
                throw ValidationException::withMessages(["parts.{$index}.action" => 'Escolha o que é esta parte.']);
            }

            if ($part->amountCents <= 0) {
                throw ValidationException::withMessages(["parts.{$index}.amount" => 'Informe o valor desta parte.']);
            }
        }

        $total = array_sum(array_map(fn (SplitPart $part) => $part->amountCents, $parts));

        if ($total !== $transaction->amount_cents) {
            throw ValidationException::withMessages([
                'parts' => 'As partes somam '.Money::format($total).', mas a transação é de '.Money::format($transaction->amount_cents).'.',
            ]);
        }

        $linked = array_values(array_filter(array_map(fn (SplitPart $part) => $part->action === 'link' ? $part->occurrenceId : null, $parts)));

        if (count($linked) !== count(array_unique($linked))) {
            throw ValidationException::withMessages(['parts' => 'Cada despesa existente só pode entrar em uma parte.']);
        }

        $result = [];

        foreach ($parts as $index => $part) {
            $title = $this->title($part->title, $transaction);
            $occurrence = null;
            $entry = null;

            if ($part->action === 'household') {
                $occurrence = $this->createHouseholdExpense($transaction, $actor, $title, $choice->notes, $part->amountCents, $date);
            } elseif ($part->action === 'link') {
                $occurrence = $this->link($transaction, $actor, $part->occurrenceId, $part->amountCents, "parts.{$index}.occurrence_id");
            } else {
                $entry = $this->createEntry($actor, $part->action, $title, $part->amountCents, $date, $choice->notes);
            }

            $item = BankSplitItem::create([
                'bank_transaction_id' => $transaction->id,
                'user_id' => $actor->id,
                'type' => $part->action === 'link' ? BankCatalogType::Linked : BankCatalogType::from($part->action),
                'amount_cents' => $part->amountCents,
                'expense_occurrence_id' => $occurrence?->id,
                'personal_transaction_id' => $entry?->id,
            ]);

            $result[] = $this->splitAudit($item);
        }

        return $result;
    }

    /**
     * Undo a split: the household expenses its parts created are removed
     * (refused once one of them was paid), the personal entries deleted and
     * the links only undone, without touching the expense.
     *
     * @return list<array{type: string, amount_cents: int, expense_occurrence_id: int|null, personal_transaction_id: int|null}> What the parts were.
     */
    private function undoSplit(BankTransaction $transaction, User $actor): array
    {
        $items = BankSplitItem::query()
            ->where('bank_transaction_id', $transaction->id)
            ->with('occurrence')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($items as $item) {
            if ($item->type === BankCatalogType::Household && $item->occurrence?->isPaid()) {
                throw ValidationException::withMessages([
                    'transaction' => "A despesa “{$item->occurrence->title}” criada nesta divisão já foi paga. Reabra o pagamento em Despesas antes de desfazer.",
                ]);
            }
        }

        $parts = [];

        foreach ($items as $item) {
            if ($item->type === BankCatalogType::Household && $item->occurrence !== null) {
                $this->expenses->delete($item->occurrence, $actor);
            }

            if ($item->personal_transaction_id !== null) {
                PersonalTransaction::query()->where('user_id', $actor->id)->whereKey($item->personal_transaction_id)->delete();
            }

            $parts[] = $this->splitAudit($item);
            $item->delete();
        }

        return $parts;
    }

    /**
     * @return array{type: string, amount_cents: int, expense_occurrence_id: int|null, personal_transaction_id: int|null}
     */
    private function splitAudit(BankSplitItem $item): array
    {
        return [
            'type' => $item->type->value,
            'amount_cents' => $item->amount_cents,
            'expense_occurrence_id' => $item->expense_occurrence_id,
            'personal_transaction_id' => $item->personal_transaction_id,
        ];
    }

    /**
     * A new household expense, pending, with the transaction's installment.
     */
    private function createHouseholdExpense(BankTransaction $transaction, User $actor, string $title, ?string $notes, int $amountCents, CarbonImmutable $date): ExpenseOccurrence
    {
        return $this->expenses->create($actor->household(), $actor, [
            'scope' => ExpenseScope::Household,
            'title' => $title,
            'notes' => $notes,
            'amount_cents' => $amountCents,
            'due_date' => $date,
            'installment_number' => $transaction->installment_number,
            'installment_count' => $transaction->installment_count,
        ]);
    }

    /**
     * An entry in the actor's personal ledger.
     *
     * @param  string  $action  personal, income or investment.
     */
    private function createEntry(User $actor, string $action, string $title, int $amountCents, CarbonImmutable $date, ?string $notes): PersonalTransaction
    {
        return PersonalTransaction::create([
            'user_id' => $actor->id,
            'type' => match ($action) {
                'personal' => TransactionType::Expense,
                'income' => TransactionType::Income,
                default => TransactionType::Investment,
            },
            'title' => $title,
            'amount_cents' => $amountCents,
            'transaction_date' => $date,
            'notes' => $notes,
        ]);
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
            'ignore', 'offset', 'settlement' => true,
            default => $transaction->isOutgoing(),
        };

        if (! $fits) {
            throw ValidationException::withMessages([
                'action' => $transaction->isOutgoing()
                    ? 'Saídas de dinheiro não podem ser receitas.'
                    : 'Entradas de dinheiro só podem ser receitas, acertos da casa ou ignoradas.',
            ]);
        }
    }

    private function title(?string $title, BankTransaction $transaction): string
    {
        $title = trim((string) $title);

        return Str::limit($title !== '' ? $title : $transaction->displayName(), 120, '');
    }

    /**
     * Whether an expense title (normalized) and a transaction key name the
     * same thing ("aluguel" and "pix aluguel fulano").
     */
    private function similar(string $title, string $key): bool
    {
        return strlen($title) >= 4 && strlen($key) >= 4 && (str_contains($key, $title) || str_contains($title, $key));
    }

    /**
     * Days since the epoch of a calendar date, to compare dates cheaply.
     */
    private function dayNumber(CarbonInterface $date): int
    {
        return intdiv((int) gmmktime(0, 0, 0, $date->month, $date->day, $date->year), 86400);
    }

    /**
     * The calendar date ("2026-11-19") of a day number.
     */
    private function dateOf(int $day): string
    {
        return gmdate('Y-m-d', $day * 86400);
    }

    /**
     * "novembro/2026".
     */
    /**
     * The house settlement for a transfer: the month whose shares add up to
     * its value. A settlement rule without such a month still suggests the
     * settlement, leaving the expenses to be picked.
     */
    private function settlementSuggestion(BankTransaction $transaction, ?BankRule $rule = null): ?BankSuggestion
    {
        if ($this->settlements->partnerOf($transaction->user) === null) {
            return null;
        }

        $match = $this->settlements->match($transaction);

        if ($match === null) {
            return $rule === null ? null : new BankSuggestion(
                new CatalogChoice('settlement', remember: false),
                'Acerto da casa (escolha as despesas)',
                $rule,
            );
        }

        $count = count($match['ids']);

        return new BankSuggestion(
            new CatalogChoice('settlement', remember: $rule === null, occurrenceIds: $match['ids']),
            'Acerto da casa: '.$this->monthLabel($match['month']).' ('.($count === 1 ? '1 despesa' : "{$count} despesas").')',
            $rule,
        );
    }

    private function monthLabel(CarbonImmutable $month): string
    {
        return $month->format('m/Y');
    }

    private function installmentLabel(ExpenseOccurrence $occurrence): string
    {
        return $occurrence->installment_number !== null ? " (parcela {$occurrence->installment_number}/{$occurrence->installment_count})" : '';
    }

    /**
     * Linking an expense already paid only attaches the bank proof, so the
     * suggestion says so.
     */
    private function paidLabel(ExpenseOccurrence $occurrence): string
    {
        return $occurrence->isPaid() ? ', já paga' : '';
    }
}
