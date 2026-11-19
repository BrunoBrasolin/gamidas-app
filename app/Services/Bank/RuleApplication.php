<?php

namespace App\Services\Bank;

use App\Enums\BankRuleAction;

/**
 * What applying an automatic rule to the inbox did: how many pending
 * transactions were looked at, how many the rule is for and how many were
 * catalogued (the others stay in the inbox with the suggestion).
 */
final readonly class RuleApplication
{
    public function __construct(
        public BankRuleAction $action,
        public int $checked,
        public int $matched,
        public int $catalogued,
    ) {
        //
    }

    /**
     * The result in a line, e.g. "120 pendentes verificadas · 5 batem com a
     * regra · 4 vinculadas · 1 continua na caixa de entrada".
     */
    public function summary(): string
    {
        if ($this->checked === 0) {
            return 'Nenhuma transação pendente na caixa de entrada.';
        }

        $parts = [$this->checked === 1 ? '1 pendente verificada' : "{$this->checked} pendentes verificadas"];

        if ($this->matched === 0) {
            $parts[] = 'nenhuma bate com a regra';

            return implode(' · ', $parts);
        }

        [$one, $many] = match ($this->action) {
            BankRuleAction::Recurring => ['vinculada', 'vinculadas'],
            BankRuleAction::Ignore => ['ignorada', 'ignoradas'],
            default => ['catalogada', 'catalogadas'],
        };

        $parts[] = $this->matched === 1 ? '1 bate com a regra' : "{$this->matched} batem com a regra";
        $parts[] = match ($this->catalogued) {
            0 => "nenhuma {$one}",
            1 => "1 {$one}",
            default => "{$this->catalogued} {$many}",
        };

        $left = $this->matched - $this->catalogued;

        if ($left > 0) {
            $parts[] = $left === 1 ? '1 continua na caixa de entrada' : "{$left} continuam na caixa de entrada";
        }

        return implode(' · ', $parts);
    }
}
