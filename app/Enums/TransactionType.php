<?php

namespace App\Enums;

enum TransactionType: string
{
    case Income = 'income';
    case Expense = 'expense';
    case Investment = 'investment';

    /**
     * The URL segment used by the personal finance routes.
     */
    public function slug(): string
    {
        return match ($this) {
            self::Income => 'income',
            self::Expense => 'expenses',
            self::Investment => 'investments',
        };
    }

    public static function fromSlug(string $slug): self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        abort(404);
    }
}
