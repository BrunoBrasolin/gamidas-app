<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\BankAccountType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A checking/savings account or credit card of a bank connection. Balances
 * are signed cents (a card balance is what is owed).
 *
 * @property int $id
 * @property int $bank_connection_id
 * @property int $user_id
 * @property string $pluggy_id
 * @property BankAccountType $type
 * @property string|null $subtype
 * @property string $name
 * @property string|null $number
 * @property int $balance_cents
 * @property string $currency_code
 * @property int|null $credit_limit_cents
 * @property int|null $available_credit_cents
 * @property CarbonImmutable|null $balance_close_date
 * @property CarbonImmutable|null $balance_due_date
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read BankConnection $connection
 */
#[Fillable([
    'bank_connection_id', 'user_id', 'pluggy_id', 'type', 'subtype', 'name', 'number', 'balance_cents',
    'currency_code', 'credit_limit_cents', 'available_credit_cents', 'balance_close_date', 'balance_due_date',
])]
class BankAccount extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BankAccountType::class,
            'balance_cents' => 'integer',
            'credit_limit_cents' => 'integer',
            'available_credit_cents' => 'integer',
            'balance_close_date' => DateOnly::class,
            'balance_due_date' => DateOnly::class,
        ];
    }

    public function isCreditCard(): bool
    {
        return $this->type === BankAccountType::Credit;
    }

    /**
     * @return BelongsTo<BankConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class, 'bank_connection_id');
    }

    /**
     * @return HasMany<BankTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    /**
     * @return HasMany<BankBill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(BankBill::class);
    }
}
