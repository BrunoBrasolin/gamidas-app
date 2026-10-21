<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\TransactionType;
use Carbon\CarbonImmutable;
use Database\Factories\PersonalTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An income, expense or investment entry in a member's personal ledger. The
 * owner is also the creator: personal records are only ever written by them.
 *
 * @property int $id
 * @property int $user_id
 * @property TransactionType $type
 * @property string $title
 * @property int $amount_cents
 * @property CarbonImmutable $transaction_date
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'type', 'title', 'amount_cents', 'transaction_date', 'notes'])]
class PersonalTransaction extends Model
{
    /** @use HasFactory<PersonalTransactionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount_cents' => 'integer',
            'transaction_date' => DateOnly::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
