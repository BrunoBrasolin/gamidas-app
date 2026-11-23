<?php

namespace App\Enums;

/**
 * Every expense belongs to the household. Personal bills (the former
 * "personal" scope) were merged into the personal ledger, Finanças pessoais
 * › Despesas; the column stays so old rows keep a valid value.
 */
enum ExpenseScope: string
{
    case Household = 'household';

    public function label(): string
    {
        return 'Casa';
    }
}
