<?php

namespace App\Services\Bank;

use App\Enums\BankRuleAction;
use App\Enums\BankRuleMatch;
use App\Models\BankRule;
use App\Models\BankTransaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * The rules each member taught the inbox. Cataloguing a transaction learns
 * (or updates) the rule for its counterparty; the next transactions that
 * match come with the same classification suggested.
 */
final class BankRuleBook
{
    /**
     * @return Collection<int, BankRule>
     */
    public function rulesOf(int $userId): Collection
    {
        return BankRule::query()->where('user_id', $userId)->get();
    }

    /**
     * The rule for a transaction: a rule for its exact value wins over rules
     * for any value; then an exact match on its key wins over "contains"
     * rules, and among those the longest pattern wins. Rules whose action
     * does not fit the direction (income for money going out) are skipped.
     *
     * @param  Collection<int, BankRule>|null  $rules  The owner's rules, when already loaded.
     */
    public function match(BankTransaction $transaction, ?Collection $rules = null): ?BankRule
    {
        $rules ??= $this->rulesOf($transaction->user_id);

        return $rules
            ->filter(fn (BankRule $rule) => $rule->user_id === $transaction->user_id
                && $rule->action->fits($transaction->direction)
                && $rule->matches($transaction->ruleKeys(), $transaction->amount_cents))
            ->sortByDesc(fn (BankRule $rule) => ($rule->amount_cents !== null ? 2000 : 0)
                + ($rule->match_mode === BankRuleMatch::Equals ? 1000 : 0)
                + strlen($rule->pattern))
            ->first();
    }

    /**
     * Remember the owner's choice for transactions like this one: creates
     * the exact rule for its key (for any value, or only for this value) or
     * updates it (adjusting a suggestion corrects the rule).
     */
    public function learn(User $owner, BankTransaction $transaction, BankRuleAction $action, ?string $title, ?int $recurringExpenseId, ?bool $autoApply, bool $onlyThisAmount = false): BankRule
    {
        $rule = BankRule::query()->firstOrNew($this->learnedKey($owner, $transaction, $onlyThisAmount));

        $rule->fill([
            'label' => Str::limit($transaction->displayName(), 160, ''),
            'action' => $action,
            'title' => in_array($action, [BankRuleAction::Recurring, BankRuleAction::Ignore, BankRuleAction::Settlement], true) ? null : $title,
            'recurring_expense_id' => $action === BankRuleAction::Recurring ? $recurringExpenseId : null,
        ]);

        if ($autoApply !== null) {
            $rule->auto_apply = $autoApply;
        }

        $this->used($rule);

        return $rule;
    }

    /**
     * The rule learn() keeps for transactions like this one, if any.
     */
    public function learnedFrom(User $owner, BankTransaction $transaction, bool $onlyThisAmount = false): ?BankRule
    {
        return BankRule::query()->where($this->learnedKey($owner, $transaction, $onlyThisAmount))->first();
    }

    /**
     * @return array{user_id: int, pattern: string, match_mode: string, amount_cents: ?int}
     */
    private function learnedKey(User $owner, BankTransaction $transaction, bool $onlyThisAmount): array
    {
        return [
            'user_id' => $owner->id,
            'pattern' => $transaction->match_key,
            'match_mode' => BankRuleMatch::Equals->value,
            'amount_cents' => $onlyThisAmount ? $transaction->amount_cents : null,
        ];
    }

    /**
     * Count a use of the rule (a confirmed suggestion or an automatic one).
     */
    public function used(BankRule $rule): void
    {
        $rule->hits++;
        $rule->last_used_at = Date::now();
        $rule->save();
    }
}
