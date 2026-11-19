<?php

namespace App\Services\Bank;

use App\Enums\BankDirection;
use App\Enums\BankReviewStatus;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pairs transactions that cancel each other out ("compensação"): a refund
 * and the charge it reverses, in the same account, with the same value and
 * opposite directions. Both sides are ignored and point at one another, so
 * nothing reaches the expenses and the net is zero.
 *
 * A strong pair — the incoming side says it is a refund ("estorno", "de
 * volta"...) and the descriptions match without that word — is paired as
 * soon as it is imported, within 60 days either way (the refund may even
 * come dated before the charge). A weak pair — same value within 7 days, on
 * a card or with the same counterparty — is only suggested.
 */
final class BankOffsetService
{
    /**
     * Words that mark the incoming side as a refund of a charge.
     */
    public const REFUND_MARKER = '/\b(estorno|estornad[oa]|de volta|devolu[cç][aã]o|reembolso|ressarcimento|cancelamento|cancelad[oa])\b/iu';

    private const STRONG_WINDOW_DAYS = 60;

    private const WEAK_WINDOW_DAYS = 7;

    public function __construct(private readonly AuditLogger $audit)
    {
        //
    }

    /**
     * Pair a pending transaction with its strong counterpart, when that one
     * is pending too. A counterpart already catalogued is never undone here:
     * the inbox suggests it instead.
     */
    public function autoPair(BankTransaction $transaction): bool
    {
        if (! $transaction->isPending() || $transaction->removed_at !== null || $transaction->offset_transaction_id !== null) {
            return false;
        }

        $match = $this->find($transaction, strongOnly: true);

        if ($match === null || ! $match['transaction']->isPending()) {
            return false;
        }

        // Only mutual best matches pair: with two equal charges, the refund
        // goes to the closest one even if the other is handled first.
        $back = $this->find($match['transaction'], strongOnly: true);

        if ($back === null || $back['transaction']->id !== $transaction->id) {
            return false;
        }

        try {
            $this->pair($transaction, $match['transaction'], null);
        } catch (ValidationException) {
            // Taken by a sync running at the same time.
            return false;
        }

        return true;
    }

    /**
     * The best counterpart of a transaction: strong pairs first, then the
     * closest date, then the oldest. Counterparts may be pending or (only
     * for strong pairs) catalogued; ignored ones and other pairs are left
     * alone.
     *
     * @return array{transaction: BankTransaction, strong: bool}|null
     */
    public function find(BankTransaction $transaction, bool $strongOnly = false): ?array
    {
        if ($transaction->amount_cents === 0 || $transaction->removed_at !== null) {
            return null;
        }

        $best = null;

        foreach ($this->sameValueOpposites($transaction, self::STRONG_WINDOW_DAYS)->get() as $candidate) {
            $strong = $this->isStrongPair($transaction, $candidate);

            if (! $strong && ($strongOnly || ! $this->isWeakPair($transaction, $candidate))) {
                continue;
            }

            if (! $strong && ! $candidate->isPending()) {
                continue;
            }

            $rank = [$strong ? 0 : 1, $this->days($transaction, $candidate), $candidate->id];

            if ($best === null || $rank < $best['rank']) {
                $best = ['transaction' => $candidate, 'strong' => $strong, 'rank' => $rank];
            }
        }

        return $best === null ? null : ['transaction' => $best['transaction'], 'strong' => $best['strong']];
    }

    /**
     * Pending transactions that could cancel each transaction out, for
     * pairing by hand: same account, same value, opposite direction, closest
     * first.
     *
     * @param  Collection<int, BankTransaction>  $transactions
     * @return array<int, list<BankTransaction>>
     */
    public function candidates(Collection $transactions): array
    {
        $pending = $transactions->filter(fn (BankTransaction $transaction) => $transaction->isPending() && $transaction->removed_at === null);

        if ($pending->isEmpty()) {
            return [];
        }

        $others = BankTransaction::query()
            ->whereIn('bank_account_id', $pending->pluck('bank_account_id')->unique()->values())
            ->whereIn('amount_cents', $pending->pluck('amount_cents')->unique()->values())
            ->where('review_status', BankReviewStatus::Pending)
            ->whereNull('removed_at')
            ->whereNull('offset_transaction_id')
            ->get();

        $result = [];

        foreach ($pending as $transaction) {
            $matches = $others
                ->filter(fn (BankTransaction $other) => $other->id !== $transaction->id
                    && $other->user_id === $transaction->user_id
                    && $other->bank_account_id === $transaction->bank_account_id
                    && $other->amount_cents === $transaction->amount_cents
                    && $other->direction !== $transaction->direction)
                ->sortBy(fn (BankTransaction $other) => [$this->days($transaction, $other), $other->id])
                ->take(10)
                ->all();

            $result[$transaction->id] = array_values($matches);
        }

        return $result;
    }

    /**
     * Pair two pending transactions. Both rows are locked, since a webhook
     * and the scheduled sync may run at the same time.
     */
    public function pair(BankTransaction $first, BankTransaction $second, ?User $actor): void
    {
        DB::transaction(function () use ($first, $second, $actor) {
            $rows = BankTransaction::query()
                ->whereKey([$first->id, $second->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $first = $rows->get($first->id);
            $second = $rows->get($second->id);

            if ($first === null || $second === null || ! $this->canPair($first, $second)) {
                throw ValidationException::withMessages([
                    'offset_transaction_id' => 'Estas transações não se anulam: precisam ser da mesma conta, com o mesmo valor, uma entrada e uma saída, ambas na caixa de entrada.',
                ]);
            }

            $now = Date::now();

            foreach ([[$first, $second], [$second, $first]] as [$side, $other]) {
                $side->update([
                    'review_status' => BankReviewStatus::Ignored,
                    'catalog_type' => null,
                    'ignore_reason' => BankTransaction::IGNORED_OFFSET,
                    'offset_transaction_id' => $other->id,
                    'reviewed_at' => $now,
                ]);
            }

            $owner = $actor ?? $first->user;

            $this->audit->record('bank.offset', $first, $actor, $owner->householdId(), null, [
                'offset_transaction_id' => $second->id,
                'amount_cents' => $first->amount_cents,
                'automatic' => $actor === null,
            ]);
        });
    }

    /**
     * Undo a pair: both sides go back to the inbox. Returns the other side.
     */
    public function unpair(BankTransaction $transaction): ?BankTransaction
    {
        return DB::transaction(function () use ($transaction) {
            $ids = array_filter([$transaction->id, $transaction->offset_transaction_id]);
            $rows = BankTransaction::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $partner = $transaction->offset_transaction_id !== null ? $rows->get($transaction->offset_transaction_id) : null;

            foreach ($rows as $row) {
                if ($row->offset_transaction_id !== null || $row->ignore_reason === BankTransaction::IGNORED_OFFSET) {
                    $row->update([
                        'review_status' => BankReviewStatus::Pending,
                        'catalog_type' => null,
                        'ignore_reason' => null,
                        'offset_transaction_id' => null,
                        'reviewed_at' => null,
                    ]);
                }
            }

            return $partner;
        });
    }

    /**
     * After a sync: pairs whose side the bank removed (and did not re-create
     * under a new id, which would have inherited the pair) are undone, and
     * the remaining side goes back to the inbox.
     */
    public function releaseVanished(BankAccount $account): int
    {
        $vanished = BankTransaction::query()
            ->where('bank_account_id', $account->id)
            ->whereNotNull('removed_at')
            ->whereNotNull('offset_transaction_id')
            ->get();

        foreach ($vanished as $transaction) {
            $this->unpair($transaction);
        }

        return $vanished->count();
    }

    /**
     * The key of a description without the refund words: "IOF de volta de
     * Zenrows.Com" and "IOF de "Zenrows.Com"" both become "iof de zenrows com".
     */
    public static function baseKey(string $description): string
    {
        return TransactionClassifier::matchKey(null, (string) preg_replace(self::REFUND_MARKER, ' ', $description));
    }

    /**
     * @return Builder<BankTransaction>
     */
    private function sameValueOpposites(BankTransaction $transaction, int $days): Builder
    {
        return BankTransaction::query()
            ->where('user_id', $transaction->user_id)
            ->where('bank_account_id', $transaction->bank_account_id)
            ->whereKeyNot($transaction->id)
            ->where('direction', $transaction->isOutgoing() ? BankDirection::In : BankDirection::Out)
            ->where('amount_cents', $transaction->amount_cents)
            ->whereNull('removed_at')
            ->whereNull('offset_transaction_id')
            ->whereIn('review_status', [BankReviewStatus::Pending, BankReviewStatus::Catalogued])
            ->whereBetween('date', [$transaction->date->subDays($days)->toDateString(), $transaction->date->addDays($days)->toDateString()]);
    }

    /**
     * The incoming side says it is a refund and, without that word, both
     * descriptions name the same thing.
     */
    private function isStrongPair(BankTransaction $first, BankTransaction $second): bool
    {
        [$in, $out] = $first->isOutgoing() ? [$second, $first] : [$first, $second];

        if (preg_match(self::REFUND_MARKER, $in->description) !== 1) {
            return false;
        }

        $inKey = self::baseKey($in->description);
        $outKey = self::baseKey($out->description);

        return $inKey === $outKey
            || (strlen($inKey) >= 4 && strlen($outKey) >= 4 && (str_contains($inKey, $outKey) || str_contains($outKey, $inKey)));
    }

    /**
     * Same value back within a week, on a credit card (where a credit that is
     * not a bill payment is almost always a refund) or with the same
     * counterparty (a PIX returned by whoever received it).
     */
    private function isWeakPair(BankTransaction $first, BankTransaction $second): bool
    {
        if ($this->days($first, $second) > self::WEAK_WINDOW_DAYS) {
            return false;
        }

        return $first->account->isCreditCard() || $first->match_key === $second->match_key;
    }

    private function canPair(BankTransaction $first, BankTransaction $second): bool
    {
        return $first->id !== $second->id
            && $first->user_id === $second->user_id
            && $first->bank_account_id === $second->bank_account_id
            && $first->amount_cents === $second->amount_cents
            && $first->direction !== $second->direction
            && $first->isPending() && $second->isPending()
            && $first->removed_at === null && $second->removed_at === null
            && $first->offset_transaction_id === null && $second->offset_transaction_id === null;
    }

    private function days(BankTransaction $first, BankTransaction $second): int
    {
        return (int) abs($first->date->diffInDays($second->date));
    }
}
