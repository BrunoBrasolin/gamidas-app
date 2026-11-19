<?php

namespace App\Models;

use App\Enums\BankRuleAction;
use App\Enums\BankRuleMatch;
use App\Services\Bank\TransactionClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A classification the owner taught the inbox, e.g. "PIX to Fulano is the
 * rent": the next transactions whose key matches the pattern come with it
 * suggested (or applied right away, with auto_apply).
 *
 * @property int $id
 * @property int $user_id
 * @property string $pattern Normalized (see {@see TransactionClassifier::normalize()}).
 * @property BankRuleMatch $match_mode
 * @property string $label The counterparty or description as shown to the owner.
 * @property BankRuleAction $action
 * @property int|null $recurring_expense_id
 * @property string|null $title
 * @property bool $auto_apply
 * @property int $hits
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read RecurringExpense|null $recurringExpense
 */
#[Fillable(['user_id', 'pattern', 'match_mode', 'label', 'action', 'recurring_expense_id', 'title', 'auto_apply', 'hits', 'last_used_at'])]
class BankRule extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'match_mode' => 'equals',
        'auto_apply' => false,
        'hits' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'match_mode' => BankRuleMatch::class,
            'action' => BankRuleAction::class,
            'auto_apply' => 'boolean',
            'hits' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function matches(string $key): bool
    {
        return match ($this->match_mode) {
            BankRuleMatch::Equals => $key === $this->pattern,
            BankRuleMatch::Contains => $this->pattern !== '' && str_contains($key, $this->pattern),
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<RecurringExpense, $this>
     */
    public function recurringExpense(): BelongsTo
    {
        return $this->belongsTo(RecurringExpense::class);
    }
}
