<?php

namespace App\Services\Bank;

use App\Models\BankRule;
use App\Models\BankTransaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies an automatic rule to what is already waiting in the inbox: a rule
 * marked "Catalogar sozinho" catalogues on import only the transactions
 * that arrive later, so marking it also catalogues, right away, the pending
 * ones it is for.
 *
 * A pending transaction is the rule's when the rule is the one the inbox
 * picks for it (a rule for its exact value or a longer pattern wins), and
 * it is catalogued only when the inbox suggests exactly that rule: a refund
 * waiting to cancel it out, or a recurring expense without a month near
 * the transaction, leaves it in the inbox, as on import.
 */
final class BankRuleApplier
{
    public function __construct(
        private readonly BankCatalogService $catalog,
        private readonly BankRuleBook $rules,
    ) {
        //
    }

    /**
     * Catalogue the owner's pending transactions the automatic rule is for.
     */
    public function apply(BankRule $rule, User $owner): RuleApplication
    {
        if ($rule->user_id !== $owner->id || ! $rule->auto_apply) {
            return new RuleApplication($rule->action, 0, 0, 0);
        }

        $rules = $this->rules->rulesOf($owner->id);
        $checked = 0;
        /** @var list<BankTransaction> $matched */
        $matched = [];

        $this->catalog->pendingOf($owner)
            ->with(['account', 'bill'])
            ->chunkById(200, function (Collection $transactions) use ($rule, $rules, $owner, &$checked, &$matched) {
                foreach ($transactions as $transaction) {
                    $checked++;

                    if ($this->rules->match($transaction, $rules)?->id === $rule->id) {
                        $transaction->setRelation('user', $owner);
                        $matched[] = $transaction;
                    }
                }
            });

        $catalogued = 0;

        foreach ($matched as $transaction) {
            $suggestion = $this->catalog->suggest($transaction, $rules, []);

            if ($suggestion?->rule === null || $suggestion->rule->id !== $rule->id) {
                continue;
            }

            try {
                DB::transaction(function () use ($transaction, $owner, $suggestion) {
                    $this->catalog->catalog($transaction, $owner, $suggestion->choice);
                    $this->rules->used($suggestion->rule);
                });
                $catalogued++;
            } catch (ValidationException) {
                // Stays in the inbox with the suggestion.
            }
        }

        return new RuleApplication($rule->action, $checked, count($matched), $catalogued);
    }

    /**
     * After cataloguing by hand with "Lembrar" and "Catalogar sozinho", apply
     * the rule just learned to the other pending transactions.
     */
    public function afterCatalog(BankTransaction $transaction, User $owner, CatalogChoice $choice): ?RuleApplication
    {
        if (! $choice->remember || $choice->autoApply !== true || $choice->action === 'offset') {
            return null;
        }

        $rule = $this->rules->learnedFrom($owner, $transaction, $choice->rememberAmount);

        if ($rule === null || ! $rule->auto_apply) {
            return null;
        }

        return $this->apply($rule, $owner);
    }
}
