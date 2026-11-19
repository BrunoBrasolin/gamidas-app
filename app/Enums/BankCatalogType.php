<?php

namespace App\Enums;

/**
 * What a catalogued transaction became.
 */
enum BankCatalogType: string
{
    /** A new household expense, split by salaries. */
    case Household = 'household';

    /** An expense in the owner's personal ledger. */
    case Personal = 'personal';

    /** Linked to an expense that already existed (a recurring month, an installment typed by hand). */
    case Linked = 'linked';

    /** Income in the owner's personal ledger. */
    case Income = 'income';

    /** An investment in the owner's personal ledger. */
    case Investment = 'investment';

    public function label(): string
    {
        return match ($this) {
            self::Household => 'Despesa da casa',
            self::Personal => 'Despesa pessoal',
            self::Linked => 'Vinculada a despesa',
            self::Income => 'Receita',
            self::Investment => 'Investimento',
        };
    }
}
