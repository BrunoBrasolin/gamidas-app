<?php

namespace App\Models;

use App\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An investment position of a bank connection (fixed income, funds, the
 * bank's savings boxes...), with its current gross balance.
 *
 * @property int $id
 * @property int $bank_connection_id
 * @property int $user_id
 * @property string $pluggy_id
 * @property string $name
 * @property string|null $type
 * @property string|null $subtype
 * @property string|null $status
 * @property int $balance_cents
 * @property int|null $invested_cents
 * @property int|null $profit_cents
 * @property int|null $withdrawal_cents
 * @property string|null $rate
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $balance_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read BankConnection $connection
 */
#[Fillable([
    'bank_connection_id', 'user_id', 'pluggy_id', 'name', 'type', 'subtype', 'status',
    'balance_cents', 'invested_cents', 'profit_cents', 'withdrawal_cents', 'rate', 'due_date', 'balance_at',
])]
class BankInvestment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance_cents' => 'integer',
            'invested_cents' => 'integer',
            'profit_cents' => 'integer',
            'withdrawal_cents' => 'integer',
            'due_date' => DateOnly::class,
            'balance_at' => 'datetime',
        ];
    }

    /**
     * What was put in: as the bank tells it or, without it, the balance
     * minus the profit the bank reports. Null when neither is known.
     */
    public function investedCents(): ?int
    {
        return $this->invested_cents ?? ($this->profit_cents !== null ? $this->balance_cents - $this->profit_cents : null);
    }

    /**
     * @return BelongsTo<BankConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class, 'bank_connection_id');
    }
}
