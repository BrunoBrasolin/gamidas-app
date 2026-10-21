<?php

namespace App\Services;

use App\Enums\OccurrenceStatus;
use App\Enums\PaymentEventType;
use App\Models\ExpenseOccurrence;
use App\Models\PaymentEvent;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Confirms and reopens payments, keeping an append-only payment history.
 *
 * Who may perform each action is decided by the ExpenseOccurrencePolicy; this
 * service guarantees the state transitions and the history.
 */
final class PaymentService
{
    public function __construct(
        private readonly ShareCalculator $shares,
        private readonly AuditLogger $audit,
    ) {
        //
    }

    /**
     * Mark an occurrence as paid. Its shares are brought up to date one last
     * time and then frozen as history.
     */
    public function confirm(ExpenseOccurrence $occurrence, User $actor): ExpenseOccurrence
    {
        return DB::transaction(function () use ($occurrence, $actor) {
            $occurrence = ExpenseOccurrence::query()->lockForUpdate()->findOrFail($occurrence->id);

            if ($occurrence->isPaid()) {
                throw ValidationException::withMessages([
                    'payment' => 'Esta despesa já foi marcada como paga.',
                ]);
            }

            $this->shares->sync($occurrence);

            $now = Date::now();

            $occurrence->update([
                'status' => OccurrenceStatus::Paid,
                'paid_at' => $now,
                'paid_by_user_id' => $actor->id,
            ]);

            PaymentEvent::create([
                'expense_occurrence_id' => $occurrence->id,
                'user_id' => $actor->id,
                'type' => PaymentEventType::Paid,
                'amount_cents' => $occurrence->amount_cents,
                'created_at' => $now,
            ]);

            $this->audit->record('payment.confirmed', $occurrence, $actor, $occurrence->household_id, null, [
                'amount_cents' => $occurrence->amount_cents,
                'shares' => $occurrence->shares()->pluck('amount_cents', 'user_id')->all(),
            ]);

            return $occurrence;
        });
    }

    /**
     * Reopen a paid occurrence. The payment history is kept and the reason is
     * recorded; the occurrence becomes pending and follows current salaries.
     */
    public function reopen(ExpenseOccurrence $occurrence, User $actor, string $reason): ExpenseOccurrence
    {
        return DB::transaction(function () use ($occurrence, $actor, $reason) {
            $occurrence = ExpenseOccurrence::query()->lockForUpdate()->findOrFail($occurrence->id);

            if (! $occurrence->isPaid()) {
                throw ValidationException::withMessages([
                    'payment' => 'Esta despesa não está paga.',
                ]);
            }

            $old = [
                'paid_at' => $occurrence->paid_at?->toIso8601String(),
                'paid_by_user_id' => $occurrence->paid_by_user_id,
                'shares' => $occurrence->shares()->pluck('amount_cents', 'user_id')->all(),
            ];

            $occurrence->update([
                'status' => OccurrenceStatus::Pending,
                'paid_at' => null,
                'paid_by_user_id' => null,
            ]);

            PaymentEvent::create([
                'expense_occurrence_id' => $occurrence->id,
                'user_id' => $actor->id,
                'type' => PaymentEventType::Reopened,
                'amount_cents' => $occurrence->amount_cents,
                'reason' => $reason,
                'created_at' => Date::now(),
            ]);

            $this->shares->sync($occurrence);

            $this->audit->record('payment.reopened', $occurrence, $actor, $occurrence->household_id, $old, [
                'reason' => $reason,
                'shares' => $occurrence->shares()->pluck('amount_cents', 'user_id')->all(),
            ]);

            return $occurrence;
        });
    }
}
