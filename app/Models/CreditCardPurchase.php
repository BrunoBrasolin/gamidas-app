<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\ExpenseScope;
use App\Enums\OccurrenceStatus;
use App\Enums\PurchaseMode;
use Carbon\CarbonImmutable;
use Database\Factories\CreditCardPurchaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A credit card purchase. Its monthly charges live in {@see ExpenseOccurrence}
 * (one per installment); the purchase itself is never counted in totals.
 *
 * @property int $id
 * @property int $household_id
 * @property int $credit_card_id
 * @property ExpenseScope $scope
 * @property int|null $owner_user_id
 * @property int|null $expense_category_id
 * @property string $description
 * @property string|null $notes
 * @property CarbonImmutable $purchase_date
 * @property PurchaseMode $mode
 * @property int $total_amount_cents
 * @property int $installment_count
 * @property CarbonImmutable $first_billing_month
 * @property int $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CreditCard $creditCard
 * @property-read ExpenseCategory|null $category
 * @property-read User $creator
 */
#[Fillable([
    'household_id', 'credit_card_id', 'scope', 'owner_user_id', 'expense_category_id', 'description',
    'notes', 'purchase_date', 'mode', 'total_amount_cents', 'installment_count', 'first_billing_month',
    'created_by_user_id', 'updated_by_user_id',
])]
class CreditCardPurchase extends Model
{
    /** @use HasFactory<CreditCardPurchaseFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => ExpenseScope::class,
            'mode' => PurchaseMode::class,
            'total_amount_cents' => 'integer',
            'installment_count' => 'integer',
            'purchase_date' => DateOnly::class,
            'first_billing_month' => DateOnly::class,
        ];
    }

    /**
     * Purchases visible to the user: household-scoped ones of their household
     * and personal ones they own.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where($query->qualifyColumn('household_id'), $user->householdId())
            ->where(fn (Builder $query) => $query
                ->where($query->qualifyColumn('scope'), ExpenseScope::Household)
                ->orWhere($query->qualifyColumn('owner_user_id'), $user->id));
    }

    public function lastBillingMonth(): CarbonImmutable
    {
        return $this->first_billing_month->addMonthsNoOverflow($this->installment_count - 1);
    }

    public function hasPaidInstallments(): bool
    {
        return $this->installments()->where('status', OccurrenceStatus::Paid)->exists();
    }

    /**
     * @return BelongsTo<CreditCard, $this>
     */
    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<ExpenseOccurrence, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(ExpenseOccurrence::class)->orderBy('installment_number');
    }
}
