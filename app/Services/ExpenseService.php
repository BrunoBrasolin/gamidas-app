<?php

namespace App\Services;

use App\Enums\ExpenseScope;
use App\Enums\OccurrenceSource;
use App\Models\ExpenseOccurrence;
use App\Models\Household;
use App\Models\User;
use App\Support\Month;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates one-off expenses (optionally in installments) and edits or removes
 * individual occurrences. The billing month always follows the due date.
 */
final class ExpenseService
{
    public function __construct(
        private readonly ShareCalculator $shares,
        private readonly AuditLogger $audit,
    ) {
        //
    }

    /**
     * Create a one-off expense. With more than one installment, each one is
     * its own occurrence with the same amount, due on the same day of the
     * following months (clamped to shorter months). Returns the first one.
     *
     * @param  array{scope: ExpenseScope, title: string, notes: ?string, amount_cents: int, due_date: CarbonImmutable}  $data
     */
    public function create(Household $household, User $actor, array $data, int $installments = 1): ExpenseOccurrence
    {
        if ($installments < 1) {
            throw new InvalidArgumentException('An expense needs at least one installment.');
        }

        return DB::transaction(function () use ($household, $actor, $data, $installments) {
            $firstMonth = $data['due_date']->startOfMonth();
            $first = null;

            for ($number = 1; $number <= $installments; $number++) {
                $month = $firstMonth->addMonthsNoOverflow($number - 1);

                $occurrence = ExpenseOccurrence::create([
                    ...$data,
                    'household_id' => $household->id,
                    'owner_user_id' => $data['scope'] === ExpenseScope::Personal ? $actor->id : null,
                    'source' => OccurrenceSource::OneOff,
                    'installment_number' => $installments > 1 ? $number : null,
                    'installment_count' => $installments > 1 ? $installments : null,
                    'billing_month' => $month,
                    'due_date' => Month::dueDate($month, $data['due_date']->day),
                    'created_by_user_id' => $actor->id,
                ]);

                $this->shares->sync($occurrence);

                $this->audit->record('expense.created', $occurrence, $actor, $household->id, null, $this->snapshot($occurrence));

                $first ??= $occurrence;
            }

            return $first;
        });
    }

    /**
     * Edit a single pending occurrence. Edited months of a recurring expense
     * are marked as customized so later template edits leave them alone and
     * must stay due within their month; other expenses move to the month of
     * their new due date.
     *
     * @param  array{title: string, notes: ?string, amount_cents: int, due_date: CarbonImmutable}  $data
     */
    public function update(ExpenseOccurrence $occurrence, User $actor, array $data): ExpenseOccurrence
    {
        return DB::transaction(function () use ($occurrence, $actor, $data) {
            $occurrence = ExpenseOccurrence::query()->lockForUpdate()->findOrFail($occurrence->id);
            $this->ensurePending($occurrence);

            $old = $this->snapshot($occurrence);

            $billingMonth = $data['due_date']->startOfMonth();

            if ($occurrence->source === OccurrenceSource::Recurring && ! $billingMonth->equalTo($occurrence->billing_month)) {
                // Months of recurring expenses stay in their slot.
                throw ValidationException::withMessages([
                    'due_date' => 'O vencimento deve ficar no mesmo mês desta recorrência.',
                ]);
            }

            $occurrence->fill([...$data, 'billing_month' => $billingMonth, 'updated_by_user_id' => $actor->id]);

            if ($occurrence->source === OccurrenceSource::Recurring
                && $occurrence->isDirty(['title', 'amount_cents', 'due_date'])) {
                $occurrence->is_customized = true;
            }

            $amountChanged = $occurrence->isDirty('amount_cents');
            $occurrence->save();

            if ($amountChanged) {
                $this->shares->sync($occurrence);
            }

            $this->audit->record('expense.updated', $occurrence, $actor, $occurrence->household_id, $old, $this->snapshot($occurrence));

            return $occurrence;
        });
    }

    /**
     * Remove a pending occurrence. Months of a recurring expense are soft
     * deleted so they are not generated again.
     */
    public function delete(ExpenseOccurrence $occurrence, User $actor): void
    {
        DB::transaction(function () use ($occurrence, $actor) {
            $occurrence = ExpenseOccurrence::query()->lockForUpdate()->findOrFail($occurrence->id);
            $this->ensurePending($occurrence);

            $this->audit->record('expense.deleted', $occurrence, $actor, $occurrence->household_id, $this->snapshot($occurrence));

            $occurrence->update(['updated_by_user_id' => $actor->id]);
            $occurrence->delete();
        });
    }

    private function ensurePending(ExpenseOccurrence $occurrence): void
    {
        if ($occurrence->isPaid()) {
            throw ValidationException::withMessages([
                'expense' => 'Despesas pagas não podem ser alteradas. Reabra o pagamento primeiro.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ExpenseOccurrence $occurrence): array
    {
        return [
            'title' => $occurrence->title,
            'scope' => $occurrence->scope->value,
            'source' => $occurrence->source->value,
            'installment_number' => $occurrence->installment_number,
            'installment_count' => $occurrence->installment_count,
            'amount_cents' => $occurrence->amount_cents,
            'billing_month' => Month::key($occurrence->billing_month),
            'due_date' => $occurrence->due_date->toDateString(),
            'status' => $occurrence->status->value,
        ];
    }
}
