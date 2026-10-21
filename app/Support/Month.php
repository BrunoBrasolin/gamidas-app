<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * Helpers for billing months, represented as the first day of the month.
 */
final class Month
{
    public const PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/';

    /**
     * The first day of the current month in the application timezone.
     */
    public static function current(): CarbonImmutable
    {
        return Date::now()->startOfMonth()->startOfDay()->toImmutable();
    }

    /**
     * Parse a "YYYY-MM" string into the first day of that month.
     */
    public static function parse(string $value): CarbonImmutable
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("Invalid month [{$value}].");
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value.'-01', config('app.timezone'));
    }

    /**
     * Parse a month from user input, falling back to the current month.
     */
    public static function fromInput(mixed $value): CarbonImmutable
    {
        if (is_string($value) && preg_match(self::PATTERN, $value) === 1) {
            $month = self::parse($value);

            if ($month->year >= 2000 && $month->year <= 2100) {
                return $month;
            }
        }

        return self::current();
    }

    /**
     * Format a month as "YYYY-MM".
     */
    public static function key(CarbonImmutable $month): string
    {
        return $month->format('Y-m');
    }

    /**
     * The date for a due day inside a month, clamped to the month's last day
     * (a due day of 31 falls on 28/29 February, 30 April, and so on).
     */
    public static function dueDate(CarbonImmutable $month, int $dueDay): CarbonImmutable
    {
        return $month->startOfMonth()->setDay(min($dueDay, $month->daysInMonth));
    }

    /**
     * Number of whole months from $from to $to (negative when $to is earlier).
     */
    public static function diff(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return ($to->year - $from->year) * 12 + ($to->month - $from->month);
    }
}
