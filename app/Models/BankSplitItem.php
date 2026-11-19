<?php

namespace App\Models;

use App\Enums\BankCatalogType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One part of a transaction whose value was split ("Dividir valor"): how
 * much of it is a new household expense, an expense that already existed,
 * a personal expense or an investment.
 *
 * @property int $id
 * @property int $bank_transaction_id
 * @property int $user_id The owner of the transaction.
 * @property BankCatalogType $type Household, Linked, Personal or Investment.
 * @property int $amount_cents
 * @property int|null $expense_occurrence_id The household expense created or the expense linked.
 * @property int|null $personal_transaction_id The personal entry created.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BankTransaction $bankTransaction
 * @property-read ExpenseOccurrence|null $occurrence
 * @property-read PersonalTransaction|null $personalTransaction
 */
#[Fillable(['bank_transaction_id', 'user_id', 'type', 'amount_cents', 'expense_occurrence_id', 'personal_transaction_id'])]
class BankSplitItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BankCatalogType::class,
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
     * @return BelongsTo<PersonalTransaction, $this>
     */
    public function personalTransaction(): BelongsTo
    {
        return $this->belongsTo(PersonalTransaction::class);
    }
}
