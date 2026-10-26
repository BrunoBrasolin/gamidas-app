<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Brazilian national holidays and business days, used by the time reports to
 * judge the daily goal. Only national holidays count: Carnaval and Corpus
 * Christi are optional days off (ponto facultativo), and state or city
 * holidays are not known.
 */
final class Holidays
{
    /**
     * Fixed-date national holidays ("m-d" => name).
     */
    private const FIXED = [
        '01-01' => 'Confraternização Universal',
        '04-21' => 'Tiradentes',
        '05-01' => 'Dia do Trabalho',
        '09-07' => 'Independência do Brasil',
        '10-12' => 'Nossa Senhora Aparecida',
        '11-02' => 'Finados',
        '11-15' => 'Proclamação da República',
        '11-20' => 'Dia Nacional de Zumbi e da Consciência Negra',
        '12-25' => 'Natal',
    ];

    /**
     * Year the Consciência Negra holiday became national (Lei 14.759/2023).
     */
    private const BLACK_CONSCIOUSNESS_SINCE = 2024;

    /**
     * The holiday name of a "Y-m-d" date, or null when it is not a holiday.
     */
    public static function name(string $date): ?string
    {
        $year = (int) substr($date, 0, 4);
        $monthDay = substr($date, 5);

        if ($monthDay === '11-20' && $year < self::BLACK_CONSCIOUSNESS_SINCE) {
            return null;
        }

        if (isset(self::FIXED[$monthDay])) {
            return self::FIXED[$monthDay];
        }

        return self::easter($year)->subDays(2)->toDateString() === $date ? 'Sexta-feira Santa' : null;
    }

    /**
     * Monday to Friday, except national holidays.
     */
    public static function isBusinessDay(string $date): bool
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, config('app.timezone'));

        return $day !== null && ! $day->isWeekend() && self::name($date) === null;
    }

    /**
     * Easter Sunday of a year (anonymous Gregorian algorithm), without
     * depending on the calendar extension.
     */
    private static function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, config('app.timezone'));
    }
}
