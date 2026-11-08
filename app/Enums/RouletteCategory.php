<?php

namespace App\Enums;

enum RouletteCategory: string
{
    case Lunch = 'lunch';
    case Dessert = 'dessert';
    case Dinner = 'dinner';

    public function label(): string
    {
        return match ($this) {
            self::Lunch => 'Almoço',
            self::Dessert => 'Sobremesa',
            self::Dinner => 'Jantar',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
