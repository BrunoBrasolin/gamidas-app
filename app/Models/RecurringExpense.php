<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\ExpenseScope;
use App\Enums\RecurringKind;
use App\Support\Month;
use Carbon\CarbonImmutable;
use Database\Factories\RecurringExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A recurring expense definition (template). Its monthly charges live in
 * {@see ExpenseOccurrence}; the template itself is never counted in totals.
 *
 * @property int $id
 * @property int $household_id
 * @property ExpenseScope $scope
 * @property int|null $owner_user_id
 * @property int|null $expense_category_id
 * @property RecurringKind $kind
 * @property string $title
 * @property string|null $notes
 * @property int $amount_cents
 * @property int $due_day
 * @property CarbonImmutable $start_month
 * @property CarbonImmutable|null $end_month
 * @property int|null $total_months
 * @property Carbon|null $ended_at
 * @property int $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Household $household
 * @property-read User|null $owner
 * @property-read ExpenseCategory|null $category
 * @property-read User $creator
 * @property-read User|null $editor
 */
#[Fillable([
    'household_id', 'scope', 'owner_user_id', 'expense_category_id', 'kind', 'title', 'notes',
    'amount_cents', 'due_day', 'start_month', 'end_month', 'total_months', 'ended_at',
    'created_by_user_id', 'updated_by_user_id',
])]
class RecurringExpense extends Model
{
    /** @use HasFactory<RecurringExpenseFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => ExpenseScope::class,
            'kind' => RecurringKind::class,
            'amount_cents' => 'integer',
            'due_day' => 'integer',
            'total_months' => 'integer',
            'start_month' => DateOnly::class,
            'end_month' => DateOnly::class,
            'ended_at' => 'datetime',
        ];
    }

    /**
     * Templates visible to the user: household-scoped ones of their household
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

    /**
     * Whether the template still produces occurrences for the given month.
     */
    public function coversMonth(CarbonImmutable $month): bool
    {
        return $month->greaterThanOrEqualTo($this->start_month)
            && ($this->end_month === null || $month->lessThanOrEqualTo($this->end_month));
    }

    public function isActive(): bool
    {
        return $this->end_month === null || $this->end_month->greaterThanOrEqualTo(Month::current());
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * @return HasMany<ExpenseOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(ExpenseOccurrence::class);
    }
}
