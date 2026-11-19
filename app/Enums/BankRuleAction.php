<?php

namespace App\Enums;

/**
 * The classification a rule suggests for matching transactions.
 */
enum BankRuleAction: string
{
    case Household = 'household';
    case Personal = 'personal';
    case Income = 'income';
    case Investment = 'investment';

    /** Link to the month of a recurring expense (e.g. the rent). */
    case Recurring = 'recurring';

    case Ignore = 'ignore';

    public function label(): string
    {
        return match ($this) {
            self::Household => 'Despesa da casa',
            self::Personal => 'Despesa pessoal',
            self::Income => 'Receita',
            self::Investment => 'Investimento',
            self::Recurring => 'Despesa recorrente',
            self::Ignore => 'Ignorar',
        };
    }

    /**
     * Whether the action fits money going out or coming in.
     */
    public function fits(BankDirection $direction): bool
    {
        return match ($this) {
            self::Income => $direction === BankDirection::In,
            self::Ignore => true,
            default => $direction === BankDirection::Out,
        };
    }
}
