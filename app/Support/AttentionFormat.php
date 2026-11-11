<?php

namespace App\Support;

use App\Enums\AttentionType;

/**
 * Text of the attention game's numbers in pt-BR, the same the report page
 * shows (resources/js/lib/attention.ts), for the PDF export.
 */
class AttentionFormat
{
    public static function points(int|float|null $points): string
    {
        return $points === null ? '—' : number_format((float) $points, 0, ',', '.');
    }

    public static function rate(int|float|null $percent): string
    {
        return $percent === null ? '—' : $percent.'%';
    }

    /** "4,3 s" */
    public static function seconds(int|float|null $ms): string
    {
        return $ms === null ? '—' : number_format($ms / 1000, 1, ',', '.').' s';
    }

    /** "2 min 05 s" */
    public static function elapsed(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $minutes = intdiv($seconds, 60);

        return $minutes > 0
            ? sprintf('%d min %02d s', $minutes, $seconds % 60)
            : $seconds.' s';
    }

    /**
     * "1,5" for an average, "—" without data.
     */
    public static function average(int|float|null $value): string
    {
        return $value === null ? '—' : number_format((float) $value, 1, ',', '.');
    }

    /**
     * What a player did in one challenge, as the report page words it.
     *
     * @param  array<string, mixed>  $round
     */
    public static function roundDetail(array $round): string
    {
        $details = is_array($round['details'] ?? null) ? $round['details'] : [];
        $detail = fn (string $key, int|string $fallback = '—') => $details[$key] ?? $fallback;

        return match (AttentionType::from($round['type'])) {
            AttentionType::Selective => "{$round['hits']}/{$detail('expected', 5)} Q encontrados, {$round['errors']} toques errados",
            AttentionType::Sustained => "Contou {$detail('answer')} (eram {$detail('expected')})",
            AttentionType::Divided => "Círculos {$detail('answer')}/{$detail('expected')} · setes {$detail('answer_secondary')}/{$detail('expected_secondary')}",
            AttentionType::Alternating => "{$round['hits']} alvos, {$round['misses']} omissões, {$round['errors']} toques errados ({$detail('perseverations', 0)} perseverações)",
            AttentionType::Voluntary => "{$round['hits']}/{$detail('expected', 6)} instruções seguidas",
        };
    }
}
