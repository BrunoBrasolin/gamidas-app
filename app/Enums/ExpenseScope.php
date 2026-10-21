<?php

namespace App\Enums;

enum ExpenseScope: string
{
    case Household = 'household';
    case Personal = 'personal';

    public function label(): string
    {
        return match ($this) {
            self::Household => 'Casa',
            self::Personal => 'Pessoal',
        };
    }
}
