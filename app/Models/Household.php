<?php

namespace App\Models;

use Database\Factories\HouseholdFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class Household extends Model
{
    /** @use HasFactory<HouseholdFactory> */
    use HasFactory;

    /**
     * @return HasMany<HouseholdMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(HouseholdMember::class)->orderBy('id');
    }

    /**
     * @return HasMany<Show, $this>
     */
    public function shows(): HasMany
    {
        return $this->hasMany(Show::class);
    }

    /**
     * @return HasMany<ShowChecklistItem, $this>
     */
    public function showChecklistItems(): HasMany
    {
        return $this->hasMany(ShowChecklistItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'household_members')->orderBy('household_members.id');
    }

    /**
     * @return HasMany<RecurringExpense, $this>
     */
    public function recurringExpenses(): HasMany
    {
        return $this->hasMany(RecurringExpense::class);
    }

    /**
     * @return HasMany<ExpenseOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(ExpenseOccurrence::class);
    }

    /**
     * @return HasMany<CalendarEvent, $this>
     */
    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }

    /**
     * Whether the given user is a member of this household.
     */
    public function hasMember(User|int $user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $this->members()->where('user_id', $userId)->exists();
    }

    /**
     * The members' salaries keyed by user id, in membership order.
     *
     * @return Collection<int, int>
     */
    public function salaries(): Collection
    {
        return $this->members()->pluck('monthly_salary_cents', 'user_id')->map(fn ($cents) => (int) $cents);
    }
}
