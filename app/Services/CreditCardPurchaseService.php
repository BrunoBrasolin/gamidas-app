<?php

namespace App\Services;

use App\Enums\ExpenseScope;
use App\Enums\OccurrenceSource;
use App\Enums\PurchaseMode;
use App\Models\CreditCard;
use App\Models\CreditCardPurchase;
use App\Models\ExpenseOccurrence;
use App\Models\Household;
use App\Models\User;
use App\Support\Month;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manages credit card purchases and their installment occurrences.
 *
 * Totals only ever count the installments, never the purchase itself, so a
 * purchase is not counted twice.
 */
final class CreditCardPurchaseService
{
    /**
     * Fields that define the installment schedule. Once an installment is paid
     * they can no longer change.
     */
    private const SCHEDULE_FIELDS = ['credit_card_id', 'scope', 'mode', 'amount_cents', 'installment_count', 'first_billing_month'];

    public function __construct(
        private readonly InstallmentPlanner $planner,
        private readonly ShareCalculator $shares,
        private readonly AuditLogger $audit,
    ) {
        //
    }

    /**
     * @param  array{credit_card_id: int, scope: ExpenseScope, expense_category_id: ?int, description: string, notes: ?string, purchase_date: CarbonImmutable, mode: PurchaseMode, amount_cents: int, installment_count: int, first_billing_month: CarbonImmutable}  $data
     */
    public function create(Household $household, User $actor, array $data): CreditCardPurchase
    {
        return DB::transaction(function () use ($household, $actor, $data) {
            $purchase = CreditCardPurchase::create([
                ...$this->purchaseAttributes($data),
                'household_id' => $household->id,
                'owner_user_id' => $data['scope'] === ExpenseScope::Personal ? $actor->id : null,
                'created_by_user_id' => $actor->id,
            ]);

            $this->createInstallments($purchase, $data['amount_cents']);

            $this->audit->record('credit_card_purchase.created', $purchase, $actor, $household->id, null, $this->snapshot($purchase));

            return $purchase;
        });
    }

    /**
     * Update a purchase. Descriptive fields always update (pending installments
     * follow them); schedule fields regenerate the installments and are only
     * allowed while no installment has been paid.
     *
     * @param  array{credit_card_id: int, scope: ExpenseScope, expense_category_id: ?int, description: string, notes: ?string, purchase_date: CarbonImmutable, mode: PurchaseMode, amount_cents: int, installment_count: int, first_billing_month: CarbonImmutable}  $data
     */
    public function update(CreditCardPurchase $purchase, User $actor, array $data): CreditCardPurchase
    {
        return DB::transaction(function () use ($purchase, $actor, $data) {
            $purchase = CreditCardPurchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $old = $this->snapshot($purchase);

            $scheduleChanged = $this->scheduleChanged($purchase, $data);

            if ($scheduleChanged && $purchase->hasPaidInstallments()) {
                throw ValidationException::withMessages([
                    'amount' => 'Esta compra já tem parcelas pagas: só é possível alterar descrição, categoria e observações.',
                ]);
            }

            $purchase->update([
                ...$this->purchaseAttributes($data),
                'owner_user_id' => $data['scope'] === ExpenseScope::Personal ? $actor->id : null,
                'updated_by_user_id' => $actor->id,
            ]);

            if ($scheduleChanged) {
                $purchase->installments()->withTrashed()->forceDelete();
                $this->createInstallments($purchase, $data['amount_cents']);
            } else {
                $purchase->installments()->where('status', 'pending')->update([
                    'title' => $purchase->description,
                    'expense_category_id' => $purchase->expense_category_id,
                    'updated_by_user_id' => $actor->id,
                ]);
            }

            $this->audit->record('credit_card_purchase.updated', $purchase, $actor, $purchase->household_id, $old, $this->snapshot($purchase));

            return $purchase;
        });
    }

    /**
     * Delete a purchase and its installments, provided none was paid.
     */
    public function delete(CreditCardPurchase $purchase, User $actor): void
    {
        DB::transaction(function () use ($purchase, $actor) {
            $purchase = CreditCardPurchase::query()->lockForUpdate()->findOrFail($purchase->id);

            if ($purchase->hasPaidInstallments()) {
                throw ValidationException::withMessages([
                    'purchase' => 'Esta compra já tem parcelas pagas e não pode ser excluída.',
                ]);
            }

            $this->audit->record('credit_card_purchase.deleted', $purchase, $actor, $purchase->household_id, $this->snapshot($purchase));

            $purchase->installments()->withTrashed()->forceDelete();
            $purchase->delete();
        });
    }

    /**
     * Move the due date of pending installments when a card's due day changes.
     */
    public function syncDueDates(CreditCard $card): void
    {
        ExpenseOccurrence::query()
            ->where('status', 'pending')
            ->whereIn('credit_card_purchase_id', $card->purchases()->select('id'))
            ->get()
            ->each(fn (ExpenseOccurrence $occurrence) => $occurrence->update([
                'due_date' => Month::dueDate($occurrence->billing_month, $card->due_day),
            ]));
    }

    private function createInstallments(CreditCardPurchase $purchase, int $amountCents): void
    {
        $card = CreditCard::findOrFail($purchase->credit_card_id);
        $salaries = Household::findOrFail($purchase->household_id)->salaries()->all();
        $plan = $this->planner->plan($purchase->mode, $amountCents, $purchase->installment_count);

        foreach ($plan as $index => $installmentCents) {
            $month = $purchase->first_billing_month->addMonthsNoOverflow($index);

            $occurrence = ExpenseOccurrence::create([
                'household_id' => $purchase->household_id,
                'scope' => $purchase->scope,
                'owner_user_id' => $purchase->owner_user_id,
                'source' => OccurrenceSource::CreditCard,
                'credit_card_purchase_id' => $purchase->id,
                'installment_number' => $index + 1,
                'installment_count' => $purchase->installment_count,
                'expense_category_id' => $purchase->expense_category_id,
                'title' => $purchase->description,
                'billing_month' => $month,
                'due_date' => Month::dueDate($month, $card->due_day),
                'amount_cents' => $installmentCents,
                'created_by_user_id' => $purchase->created_by_user_id,
            ]);

            $this->shares->sync($occurrence, $salaries);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function purchaseAttributes(array $data): array
    {
        return [
            'credit_card_id' => $data['credit_card_id'],
            'scope' => $data['scope'],
            'expense_category_id' => $data['expense_category_id'],
            'description' => $data['description'],
            'notes' => $data['notes'],
            'purchase_date' => $data['purchase_date'],
            'mode' => $data['mode'],
            'total_amount_cents' => $this->planner->total($data['mode'], $data['amount_cents'], $data['installment_count']),
            'installment_count' => $data['installment_count'],
            'first_billing_month' => $data['first_billing_month'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function scheduleChanged(CreditCardPurchase $purchase, array $data): bool
    {
        $current = [
            'credit_card_id' => $purchase->credit_card_id,
            'scope' => $purchase->scope,
            'mode' => $purchase->mode,
            'amount_cents' => $purchase->mode === PurchaseMode::FixedMonthly
                ? intdiv($purchase->total_amount_cents, max(1, $purchase->installment_count))
                : $purchase->total_amount_cents,
            'installment_count' => $purchase->installment_count,
            'first_billing_month' => Month::key($purchase->first_billing_month),
        ];

        $incoming = [...$data, 'first_billing_month' => Month::key($data['first_billing_month'])];

        foreach (self::SCHEDULE_FIELDS as $field) {
            if ($current[$field] !== $incoming[$field]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(CreditCardPurchase $purchase): array
    {
        return [
            'description' => $purchase->description,
            'credit_card_id' => $purchase->credit_card_id,
            'scope' => $purchase->scope->value,
            'mode' => $purchase->mode->value,
            'total_amount_cents' => $purchase->total_amount_cents,
            'installment_count' => $purchase->installment_count,
            'first_billing_month' => Month::key($purchase->first_billing_month),
            'purchase_date' => $purchase->purchase_date->toDateString(),
        ];
    }
}
