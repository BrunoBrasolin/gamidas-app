<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One household expense paid by a house settlement ("Acerto da casa"): a
 * transfer between the two members, seen in the bank of one of them, that
 * pays the share the payer owed on it.
 *
 * @property int $id
 * @property int $bank_transaction_id
 * @property int $user_id The owner of the transaction (who settled).
 * @property int $expense_occurrence_id
 * @property int $payer_user_id The member who owed the share (not the creator of the expense).
 * @property int $amount_cents The payer's share when it was settled.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BankTransaction $bankTransaction
 * @property-read ExpenseOccurrence $occurrence
 * @property-read User $payer
 */
#[Fillable(['bank_transaction_id', 'user_id', 'expense_occurrence_id', 'payer_user_id', 'amount_cents'])]
class BankSettlementItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BankTransaction, $this>
     */
    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    /**
     * @return BelongsTo<ExpenseOccurrence, $this>
     */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(ExpenseOccurrence::class, 'expense_occurrence_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_user_id');
    }
}
