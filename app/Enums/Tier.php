<?php

namespace App\Enums;

/**
 * Tiers of a tier list (games or shows), best first.
 */
enum Tier: string
{
    case S = 'S';
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
    case F = 'F';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
