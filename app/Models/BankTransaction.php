<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\BankCatalogType;
use App\Enums\BankChannel;
use App\Enums\BankDirection;
use App\Enums\BankReviewStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A movement imported from the bank (a PIX, a card purchase, a transfer...)
 * waiting in its owner's inbox to be catalogued: as a household or personal
 * expense, linked to an expense that already exists, as income or an
 * investment, or ignored. Amounts are absolute cents with a direction.
 *
 * @property int $id
 * @property int $bank_account_id
 * @property int $user_id
 * @property string $pluggy_id
 * @property CarbonImmutable $date
 * @property string $description
 * @property int $amount_cents
 * @property BankDirection $direction
 * @property BankChannel $channel
 * @property string $bank_status POSTED or PENDING (an open card bill).
 * @property string|null $operation_type
 * @property string|null $counterparty_name
 * @property string|null $counterparty_document Masked.
 * @property string $match_key
 * @property int|null $installment_number
 * @property int|null $installment_count
 * @property string|null $pluggy_bill_id
 * @property CarbonImmutable|null $purchase_date
 * @property BankReviewStatus $review_status
 * @property BankCatalogType|null $catalog_type
 * @property string|null $ignore_reason
 * @property int|null $expense_occurrence_id
 * @property int|null $personal_transaction_id
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $removed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read BankAccount $account
 * @property-read User $user
 * @property-read ExpenseOccurrence|null $expenseOccurrence
 * @property-read PersonalTransaction|null $personalTransaction
 * @property-read BankBill|null $bill
 */
#[Fillable([
    'bank_account_id', 'user_id', 'pluggy_id', 'date', 'description', 'amount_cents', 'direction', 'channel',
    'bank_status', 'operation_type', 'counterparty_name', 'counterparty_document', 'match_key',
    'installment_number', 'installment_count', 'pluggy_bill_id', 'purchase_date', 'review_status',
    'catalog_type', 'ignore_reason', 'expense_occurrence_id', 'personal_transaction_id',
    'reviewed_at', 'removed_at',
])]
class BankTransaction extends Model
{
    /**
     * Ignored automatically: paying the card bill is not a new expense, its
     * purchases already come in through the card.
     */
    public const IGNORED_BILL_PAYMENT = 'bill_payment';

    /**
     * Ignored by the owner.
     */
    public const IGNORED_BY_USER = 'user';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'review_status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => DateOnly::class,
            'amount_cents' => 'integer',
            'direction' => BankDirection::class,
            'channel' => BankChannel::class,
            'installment_number' => 'integer',
            'installment_count' => 'integer',
            'purchase_date' => DateOnly::class,
            'review_status' => BankReviewStatus::class,
            'catalog_type' => BankCatalogType::class,
            'reviewed_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->review_status === BankReviewStatus::Pending;
    }

    public function isCatalogued(): bool
    {
        return $this->review_status === BankReviewStatus::Catalogued;
    }

    public function isOutgoing(): bool
    {
        return $this->direction === BankDirection::Out;
    }

    /**
     * The name to show: the counterparty when the bank sent one, otherwise
     * the description.
     */
    public function displayName(): string
    {
        return $this->counterparty_name ?? $this->description;
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ExpenseOccurrence, $this>
     */
    public function expenseOccurrence(): BelongsTo
    {
        return $this->belongsTo(ExpenseOccurrence::class);
    }

    /**
     * @return BelongsTo<PersonalTransaction, $this>
     */
    public function personalTransaction(): BelongsTo
    {
        return $this->belongsTo(PersonalTransaction::class);
    }

    /**
     * The credit card bill the transaction is in, once the bill is closed.
     *
     * @return BelongsTo<BankBill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(BankBill::class, 'pluggy_bill_id', 'pluggy_id');
    }
}
