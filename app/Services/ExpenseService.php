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

/**
 * Creates one-off expenses and edits or removes individual occurrences.
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
     * @param  array{scope: ExpenseScope, title: string, notes: ?string, expense_category_id: ?int, amount_cents: int, billing_month: CarbonImmutable, due_date: CarbonImmutable}  $data
     */
    public function create(Household $household, User $actor, array $data): ExpenseOccurrence
    {
        return DB::transaction(function () use ($household, $actor, $data) {
            $occurrence = ExpenseOccurrence::create([
                ...$data,
                'household_id' => $household->id,
                'owner_user_id' => $data['scope'] === ExpenseScope::Personal ? $actor->id : null,
                'source' => OccurrenceSource::OneOff,
                'created_by_user_id' => $actor->id,
            ]);

            $this->shares->sync($occurrence);

            $this->audit->record('expense.created', $occurrence, $actor, $household->id, null, $this->snapshot($occurrence));

            return $occurrence;
        });
    }

    /**
     * Edit a single pending occurrence. Edited months of a recurring expense
     * are marked as customized so later template edits leave them alone.
     * Installment amounts are defined by their purchase and cannot change here.
     *
     * @param  array{title: string, notes: ?string, expense_category_id: ?int, amount_cents: int, billing_month: CarbonImmutable, due_date: CarbonImmutable}  $data
     */
    public function update(ExpenseOccurrence $occurrence, User $actor, array $data): ExpenseOccurrence
    {
        return DB::transaction(function () use ($occurrence, $actor, $data) {
            $occurrence = ExpenseOccurrence::query()->lockForUpdate()->findOrFail($occurrence->id);
            $this->ensurePending($occurrence);

            $old = $this->snapshot($occurrence);

            if ($occurrence->source !== OccurrenceSource::OneOff) {
                // Months of recurring expenses and installments stay in their slot.
                $data['billing_month'] = $occurrence->billing_month;
            }

            if ($occurrence->source === OccurrenceSource::CreditCard) {
                $data['amount_cents'] = $occurrence->amount_cents;
            }

            $occurrence->fill([...$data, 'updated_by_user_id' => $actor->id]);

            if ($occurrence->source === OccurrenceSource::Recurring
                && $occurrence->isDirty(['title', 'expense_category_id', 'amount_cents', 'due_date'])) {
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
     * deleted so they are not generated again; installments can only be
     * removed together with their purchase.
     */
    public function delete(ExpenseOccurrence $occurrence, User $actor): void
    {
        DB::transaction(function () use ($occurrence, $actor) {
            $occurrence = ExpenseOccurrence::query()->lockForUpdate()->findOrFail($occurrence->id);
            $this->ensurePending($occurrence);

            if ($occurrence->source === OccurrenceSource::CreditCard) {
                throw ValidationException::withMessages([
                    'expense' => 'Parcelas de cartão só podem ser removidas excluindo a compra.',
                ]);
            }

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
            'amount_cents' => $occurrence->amount_cents,
            'expense_category_id' => $occurrence->expense_category_id,
            'billing_month' => Month::key($occurrence->billing_month),
            'due_date' => $occurrence->due_date->toDateString(),
            'status' => $occurrence->status->value,
        ];
    }
}
