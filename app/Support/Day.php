<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Helpers for local calendar dates ("Y-m-d" in the application timezone),
 * used by the time-tracking module. Dates are never converted through UTC,
 * so an entry recorded for a day always stays on that day.
 */
final class Day
{
    public const PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    public const MIN = '2000-01-01';

    public const MAX = '2100-12-31';

    public static function today(): CarbonImmutable
    {
        return Date::today()->toImmutable();
    }

    /**
     * Parse a "Y-m-d" date, or null when it is malformed, impossible
     * (2026-02-30) or outside the supported range.
     */
    public static function tryParse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            return null;
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone'));

        if ($day === null || $day->toDateString() !== $value || $value < self::MIN || $value > self::MAX) {
            return null;
        }

        return $day;
    }

    /**
     * Parse a date from a route segment, answering 404 when it is invalid.
     */
    public static function fromRoute(string $value): CarbonImmutable
    {
        return self::tryParse($value) ?? abort(404);
    }

    /**
     * Parse a date from user input, falling back to today.
     */
    public static function fromInput(mixed $value): CarbonImmutable
    {
        return self::tryParse($value) ?? self::today();
    }

    /**
     * The Monday of the week containing the day (weeks run Monday–Sunday).
     */
    public static function startOfWeek(CarbonImmutable $day): CarbonImmutable
    {
        return $day->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
    }

    /**
     * Every date from $from to $to inclusive, as "Y-m-d".
     *
     * @return list<string>
     */
    public static function range(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }
}
