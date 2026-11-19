<?php

namespace App\Services\Bank;

use App\Enums\OccurrenceStatus;
use App\Models\BankSettlementItem;
use App\Models\BankTransaction;
use App\Models\ExpenseOccurrence;
use App\Models\ExpenseShare;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Services\PaymentService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The house settlement ("Acerto da casa"): each month one member transfers
 * to the other the shares they owe on the household expenses the other one
 * registered. Seen in the bank of either member, the transfer is linked to
 * those expenses, which become paid on the transfer's date in the name of
 * the member who owed the share.
 *
 * The transfer may be net: shares the receiver owes on expenses the sender
 * registered are discounted from it, and those expenses are paid too, in the
 * receiver's name. Only for households of exactly two members.
 */
final class BankSettlementService
{
    /**
     * Months before and after the current one where expenses are offered.
     */
    private const MONTHS_BACK = 12;

    private const MONTHS_AHEAD = 3;

    private const MAX_CANDIDATES = 500;

    /**
     * Candidates per user, loaded once per request.
     *
     * @var array<int, list<SettlementCandidate>>
     */
    private array $candidates = [];

    /**
     * @var array<int, User|null>
     */
    private array $partners = [];

    public function __construct(private readonly PaymentService $payments)
    {
        //
    }

    /**
     * The other member of the user's household, when it has exactly two.
     */
    public function partnerOf(User $user): ?User
    {
        if (! array_key_exists($user->id, $this->partners)) {
            $members = HouseholdMember::query()->where('household_id', $user->householdId())->with('user')->get();

            $this->partners[$user->id] = $members->count() === 2
                ? $members->first(fn (HouseholdMember $member) => $member->user_id !== $user->id)?->user
                : null;
        }

        return $this->partners[$user->id];
    }

    /**
     * The household expenses the user may settle with the other member,
     * oldest first: pending ones with an amount, from a year back to three
     * months ahead, and those already paid by a settlement in the other
     * member's bank (the same transfer, seen in this bank, links them
     * without paying again).
     *
     * @return list<SettlementCandidate>
     */
    public function candidates(User $user): array
    {
        if (array_key_exists($user->id, $this->candidates)) {
            return $this->candidates[$user->id];
        }

        $partner = $this->partnerOf($user);

        if ($partner === null) {
            return $this->candidates[$user->id] = [];
        }

        $occurrences = ExpenseOccurrence::query()
            ->household((int) $user->householdId())
            ->whereBetween('due_date', [
                Date::today()->subMonthsNoOverflow(self::MONTHS_BACK)->startOfMonth()->toDateString(),
                Date::today()->addMonthsNoOverflow(self::MONTHS_AHEAD)->endOfMonth()->toDateString(),
            ])
            ->where('amount_cents', '>', 0)
            ->whereIn('created_by_user_id', [$user->id, $partner->id])
            ->whereDoesntHave('settlementItems', fn (Builder $query) => $query->where('user_id', $user->id))
            ->where(fn (Builder $query) => $query
                ->where('status', OccurrenceStatus::Pending)
                ->orWhereHas('settlementItems', fn (Builder $query) => $query->where('user_id', $partner->id)))
            ->with('shares')
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATES)
            ->get();

        $result = [];

        foreach ($occurrences as $occurrence) {
            $payerId = $occurrence->created_by_user_id === $user->id ? $partner->id : $user->id;
            $share = $occurrence->shares->first(fn (ExpenseShare $share) => $share->user_id === $payerId)?->amount_cents;

            if ($share === null || $share === 0) {
                continue;
            }

            $result[] = new SettlementCandidate($occurrence, $payerId, $share, $occurrence->isPaid());
        }

        return $this->candidates[$user->id] = $result;
    }

    /**
     * The expenses a transfer most likely settles: the month (nearest to the
     * transfer first, the earlier on a tie) whose shares add up exactly to
     * its value, counting only what the sender owed or that net of what they
     * were owed back. Null when no month adds up.
     *
     * @return array{ids: list<int>, month: CarbonImmutable}|null
     */
    public function match(BankTransaction $transaction): ?array
    {
        $user = $transaction->user;
        $partner = $this->partnerOf($user);

        if ($partner === null || $transaction->amount_cents === 0) {
            return null;
        }

        $senderId = $transaction->isOutgoing() ? $user->id : $partner->id;
        $reference = $transaction->date->year * 12 + $transaction->date->month;

        // Nearest month first; between two equally near, the earlier one
        // (the transfer usually pays the month that just ended).
        $months = collect($this->candidates($user))
            ->groupBy(fn (SettlementCandidate $candidate) => $candidate->occurrence->billing_month->year * 12 + $candidate->occurrence->billing_month->month)
            ->sortBy(fn ($candidates, int $month) => abs($month - $reference) * 2 + ($month > $reference ? 1 : 0));

        foreach ($months as $candidates) {
            $owed = $candidates->filter(fn (SettlementCandidate $candidate) => $candidate->payerId === $senderId);
            $gross = (int) $owed->sum(fn (SettlementCandidate $candidate) => $candidate->shareCents);
            $net = $gross - (int) $candidates
                ->reject(fn (SettlementCandidate $candidate) => $candidate->payerId === $senderId)
                ->sum(fn (SettlementCandidate $candidate) => $candidate->shareCents);

            $chosen = match (true) {
                $owed->isNotEmpty() && $gross === $transaction->amount_cents => $owed,
                $owed->isNotEmpty() && $net === $transaction->amount_cents => $candidates,
                default => null,
            };

            if ($chosen !== null) {
                return [
                    'ids' => array_values($chosen->map(fn (SettlementCandidate $candidate) => $candidate->occurrence->id)->all()),
                    'month' => $chosen->first()->occurrence->billing_month,
                ];
            }
        }

        return null;
    }

    /**
     * Pay the chosen expenses with the transfer, all or nothing: each one
     * becomes paid on the transfer's date in the name of the member who owed
     * the share. Expenses the other member already settled from their bank
     * are only linked.
     *
     * @param  list<int>  $occurrenceIds
     * @return array{count: int, covered_cents: int} What the sender's shares add up to, net of the shares owed back.
     */
    public function settle(BankTransaction $transaction, User $actor, array $occurrenceIds): array
    {
        $partner = $this->partnerOf($actor);

        if ($partner === null) {
            throw ValidationException::withMessages(['action' => 'O acerto da casa só funciona numa residência de duas pessoas.']);
        }

        $ids = array_values(array_unique($occurrenceIds));

        if ($ids === []) {
            throw ValidationException::withMessages(['occurrence_ids' => 'Escolha as despesas do acerto.']);
        }

        return DB::transaction(function () use ($transaction, $actor, $partner, $ids) {
            $occurrences = ExpenseOccurrence::query()
                ->household((int) $actor->householdId())
                ->whereKey($ids)
                ->orderBy('due_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($occurrences->count() !== count($ids)) {
                throw ValidationException::withMessages(['occurrence_ids' => 'Alguma despesa escolhida não está mais disponível. Recarregue a página e escolha de novo.']);
            }

            $senderId = $transaction->isOutgoing() ? $actor->id : $partner->id;
            $paidAt = $transaction->date->setTime(12, 0);
            $note = 'Acerto da casa: '.$transaction->channel->label().' de '.$transaction->date->format('d/m/Y').' ('.Money::format($transaction->amount_cents).')';
            $covered = 0;

            foreach ($occurrences as $occurrence) {
                $payer = match ($occurrence->created_by_user_id) {
                    $actor->id => $partner,
                    $partner->id => $actor,
                    default => throw ValidationException::withMessages(['occurrence_ids' => "“{$occurrence->title}” foi cadastrada por quem não está mais na casa."]),
                };

                $settlements = BankSettlementItem::query()->where('expense_occurrence_id', $occurrence->id)->pluck('user_id');

                if ($settlements->contains($actor->id)) {
                    throw ValidationException::withMessages(['occurrence_ids' => "“{$occurrence->title}” já está em outro acerto seu."]);
                }

                if ($occurrence->isPaid()) {
                    // The other member settled it from their bank: this side
                    // of the same transfer only links it.
                    if (! $settlements->contains($partner->id)) {
                        throw ValidationException::withMessages(['occurrence_ids' => "“{$occurrence->title}” já foi paga."]);
                    }
                } else {
                    $response = Gate::forUser($actor)->inspect('settle', $occurrence);

                    if ($response->denied()) {
                        throw ValidationException::withMessages(['occurrence_ids' => $response->message() ?? 'Esta despesa não pode entrar no acerto.']);
                    }

                    $occurrence = $this->payments->confirm($occurrence, $actor, $paidAt, $payer, $note);
                }

                // The share frozen by the payment.
                $share = (int) ExpenseShare::query()
                    ->where('expense_occurrence_id', $occurrence->id)
                    ->where('user_id', $payer->id)
                    ->value('amount_cents');

                BankSettlementItem::create([
                    'bank_transaction_id' => $transaction->id,
                    'user_id' => $actor->id,
                    'expense_occurrence_id' => $occurrence->id,
                    'payer_user_id' => $payer->id,
                    'amount_cents' => $share,
                ]);

                $covered += $payer->id === $senderId ? $share : -$share;
            }

            unset($this->candidates[$actor->id]);

            return ['count' => $occurrences->count(), 'covered_cents' => $covered];
        });
    }

    /**
     * Undo the transfer's settlement: its expenses become pending again,
     * except those the other member's bank settled too, which stay paid and
     * only lose this side's link.
     */
    public function undo(BankTransaction $transaction, User $actor): void
    {
        DB::transaction(function () use ($transaction, $actor) {
            $items = BankSettlementItem::query()->where('bank_transaction_id', $transaction->id)->lockForUpdate()->get();

            foreach ($items as $item) {
                $item->delete();

                $occurrence = ExpenseOccurrence::query()->find($item->expense_occurrence_id);

                if ($occurrence === null || ! $occurrence->isPaid() || $occurrence->isSettled()) {
                    continue;
                }

                $this->payments->reopen($occurrence, $actor, 'Acerto da casa desfeito na caixa de entrada.');
            }

            unset($this->candidates[$actor->id]);
        });
    }
}
