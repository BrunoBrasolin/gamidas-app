<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\ExpenseScope;
use App\Enums\OccurrenceSource;
use App\Enums\OccurrenceStatus;
use App\Support\Month;
use Carbon\CarbonImmutable;
use Database\Factories\ExpenseOccurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A single monthly charge: a one-off expense, one month of a recurring
 * expense, or one installment of an expense split into installments. Every total in the
 * application is computed from these rows.
 *
 * @property int $id
 * @property int $household_id
 * @property ExpenseScope $scope
 * @property int|null $owner_user_id
 * @property OccurrenceSource $source
 * @property int|null $recurring_expense_id
 * @property int|null $installment_number
 * @property int|null $installment_count
 * @property string $title
 * @property string|null $notes
 * @property CarbonImmutable $billing_month
 * @property CarbonImmutable $due_date
 * @property int $amount_cents
 * @property OccurrenceStatus $status
 * @property Carbon|null $paid_at
 * @property int|null $paid_by_user_id
 * @property bool $is_customized
 * @property int $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Household $household
 * @property-read User|null $owner
 * @property-read RecurringExpense|null $recurringExpense
 * @property-read User $creator
 * @property-read User|null $editor
 * @property-read User|null $payer
 */
#[Fillable([
    'household_id', 'scope', 'owner_user_id', 'source', 'recurring_expense_id',
    'installment_number', 'installment_count', 'title', 'notes', 'billing_month',
    'due_date', 'amount_cents', 'status', 'paid_at', 'paid_by_user_id', 'is_customized',
    'created_by_user_id', 'updated_by_user_id',
])]
class ExpenseOccurrence extends Model
{
    /** @use HasFactory<ExpenseOccurrenceFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'is_customized' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => ExpenseScope::class,
            'source' => OccurrenceSource::class,
            'status' => OccurrenceStatus::class,
            'amount_cents' => 'integer',
            'installment_number' => 'integer',
            'installment_count' => 'integer',
            'billing_month' => DateOnly::class,
            'due_date' => DateOnly::class,
            'paid_at' => 'datetime',
            'is_customized' => 'boolean',
        ];
    }

    public function isPaid(): bool
    {
        return $this->status === OccurrenceStatus::Paid;
    }

    /**
     * A pending occurrence only becomes overdue once its due month is over:
     * one due on 10/10 is overdue from 01/11 on.
     */
    public function isOverdue(): bool
    {
        return ! $this->isPaid() && $this->due_date->lt(Month::current());
    }

    /**
     * A pending month of a variable recurring expense (e.g. the electricity
     * bill) whose amount has not been filled in yet. It cannot be paid until
     * it has one.
     */
    public function awaitsAmount(): bool
    {
        return ! $this->isPaid() && $this->amount_cents === 0;
    }

    public function isHousehold(): bool
    {
        return $this->scope === ExpenseScope::Household;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeHousehold(Builder $query, int $householdId): void
    {
        $query->where($query->qualifyColumn('household_id'), $householdId)->where($query->qualifyColumn('scope'), ExpenseScope::Household);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePersonalOf(Builder $query, User $user): void
    {
        $query->where($query->qualifyColumn('household_id'), $user->householdId())
            ->where($query->qualifyColumn('scope'), ExpenseScope::Personal)
            ->where($query->qualifyColumn('owner_user_id'), $user->id);
    }

    /**
     * Occurrences visible to the user: household-scoped ones of their
     * household and personal ones they own.
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
     * Pending occurrences due before the current month (see {@see isOverdue()}).
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), OccurrenceStatus::Pending)
            ->where($query->qualifyColumn('due_date'), '<', Month::current()->toDateString());
    }

    /**
     * Pending occurrences still waiting for their amount (see {@see awaitsAmount()}).
     *
     * @param  Builder<self>  $query
     */
    public function scopeAwaitingAmount(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), OccurrenceStatus::Pending)
            ->where($query->qualifyColumn('amount_cents'), 0);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForMonth(Builder $query, CarbonImmutable $month): void
    {
        $query->where($query->qualifyColumn('billing_month'), $month->startOfMonth()->toDateString());
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
     * @return BelongsTo<RecurringExpense, $this>
     */
    public function recurringExpense(): BelongsTo
    {
        return $this->belongsTo(RecurringExpense::class);
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
     * @return BelongsTo<User, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    /**
     * @return HasMany<ExpenseShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(ExpenseShare::class);
    }

    /**
     * @return HasMany<PaymentEvent, $this>
     */
    public function paymentEvents(): HasMany
    {
        return $this->hasMany(PaymentEvent::class)->orderBy('id');
    }
}
