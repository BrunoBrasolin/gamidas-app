<?php

namespace App\Enums;

/**
 * How a rule's pattern is compared with a transaction's key (its normalized
 * counterparty name or description).
 */
enum BankRuleMatch: string
{
    case Equals = 'equals';
    case Contains = 'contains';

    public function label(): string
    {
        return match ($this) {
            self::Equals => 'É igual a',
            self::Contains => 'Contém',
        };
    }
}
