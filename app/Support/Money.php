<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Conversions between decimal strings and integer cents.
 *
 * Monetary values are always stored and computed as integer cents; this class
 * never goes through floating point.
 */
final class Money
{
    /**
     * Largest amount accepted for a single expense or transaction (R$ 9.999.999,99).
     */
    public const MAX_AMOUNT_CENTS = 999_999_999;

    /**
     * Largest monthly salary accepted (R$ 999.999,99).
     */
    public const MAX_SALARY_CENTS = 99_999_999;

    /**
     * Pattern accepted for decimal amounts submitted by forms, e.g. "1234.56".
     */
    public const DECIMAL_PATTERN = '/^\d{1,10}(\.\d{1,2})?$/';

    /**
     * Convert a decimal string such as "1234.5" into cents (123450).
     */
    public static function toCents(string $decimal): int
    {
        $decimal = trim($decimal);

        if (preg_match(self::DECIMAL_PATTERN, $decimal) !== 1) {
            throw new InvalidArgumentException("Invalid monetary amount [{$decimal}].");
        }

        [$units, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');

        return ((int) $units * 100) + (int) str_pad($fraction, 2, '0');
    }

    /**
     * Convert cents into a decimal string with two places, e.g. "1234.50".
     */
    public static function toDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Format cents in Brazilian Real, e.g. "R$ 1.234,56".
     */
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.'R$ '.number_format(intdiv($cents, 100), 0, ',', '.').','.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
