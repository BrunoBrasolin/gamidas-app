<?php

namespace App\Enums;

/**
 * Pluggy account types: checking/savings accounts and credit cards.
 */
enum BankAccountType: string
{
    case Bank = 'BANK';
    case Credit = 'CREDIT';

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Conta',
            self::Credit => 'Cartão de crédito',
        };
    }
}
