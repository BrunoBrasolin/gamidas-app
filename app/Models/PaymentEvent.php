<?php

namespace App\Models;

use App\Enums\PaymentEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An append-only record of a payment confirmation or reopening.
 *
 * @property int $id
 * @property int $expense_occurrence_id
 * @property int $user_id
 * @property PaymentEventType $type
 * @property int $amount_cents
 * @property string|null $reason
 * @property Carbon $created_at
 * @property-read User $user
 */
#[Fillable(['expense_occurrence_id', 'user_id', 'type', 'amount_cents', 'reason', 'created_at'])]
#[WithoutTimestamps]
class PaymentEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PaymentEventType::class,
            'amount_cents' => 'integer',
            'created_at' => 'datetime',
        ];
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
