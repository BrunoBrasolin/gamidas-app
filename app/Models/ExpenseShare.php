<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The portion of a household occurrence owed by one member, with the salary
 * figures it was calculated from.
 *
 * @property int $id
 * @property int $expense_occurrence_id
 * @property int $user_id
 * @property int $amount_cents
 * @property int $salary_cents
 * @property int $combined_salary_cents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['expense_occurrence_id', 'user_id', 'amount_cents', 'salary_cents', 'combined_salary_cents'])]
class ExpenseShare extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'salary_cents' => 'integer',
            'combined_salary_cents' => 'integer',
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
