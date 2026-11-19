<?php

namespace App\Models;

use App\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A closed credit card bill ("fatura"). Shown for reference and used as the
 * due date of the card purchases in it; it never becomes an expense, so a
 * bill payment is not counted on top of its purchases.
 *
 * @property int $id
 * @property int $bank_account_id
 * @property string $pluggy_id
 * @property CarbonImmutable|null $closing_date
 * @property CarbonImmutable $due_date
 * @property int $total_cents
 * @property int|null $minimum_payment_cents
 * @property int $finance_charges_cents
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read BankAccount $account
 */
#[Fillable(['bank_account_id', 'pluggy_id', 'closing_date', 'due_date', 'total_cents', 'minimum_payment_cents', 'finance_charges_cents'])]
class BankBill extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'closing_date' => DateOnly::class,
            'due_date' => DateOnly::class,
            'total_cents' => 'integer',
            'minimum_payment_cents' => 'integer',
            'finance_charges_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
}
