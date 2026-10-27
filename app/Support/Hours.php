<?php

namespace App\Support;

/**
 * Durations of the complementary hours module, kept as integer minutes and
 * typed as hours: "4", "1,5", "1.5", "1:30" or "1h30".
 */
final class Hours
{
    /** Largest duration accepted, in hours. */
    public const MAX_HOURS = 9999;

    /**
     * Minutes for a typed duration, or null when it is malformed, zero or
     * larger than {@see self::MAX_HOURS}.
     */
    public static function parse(string $value): ?int
    {
        $value = strtolower(str_replace(' ', '', trim($value)));

        if (preg_match('/^(\d{1,4})(?:[.,](\d{1,2}))?h?$/', $value, $match) === 1) {
            $fraction = isset($match[2]) ? (int) round(((int) str_pad($match[2], 2, '0')) * 60 / 100) : 0;
            $minutes = (int) $match[1] * 60 + $fraction;
        } elseif (preg_match('/^(\d{1,4})(?::|h)([0-5]\d)(?:min|m)?$/', $value, $match) === 1) {
            $minutes = (int) $match[1] * 60 + (int) $match[2];
        } else {
            return null;
        }

        return $minutes > 0 && $minutes <= self::MAX_HOURS * 60 ? $minutes : null;
    }

    /**
     * "4h", "1h30", "45min" or "0h".
     */
    public static function format(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours === 0 && $rest > 0) {
            return $rest.'min';
        }

        return $rest === 0 ? $hours.'h' : $hours.'h'.str_pad((string) $rest, 2, '0', STR_PAD_LEFT);
    }
}
