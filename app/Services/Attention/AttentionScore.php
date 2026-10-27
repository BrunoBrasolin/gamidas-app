<?php

namespace App\Services\Attention;

use App\Enums\AttentionType;

/**
 * Points of a round, out of 1000:
 *
 * - performance: 5 per accuracy point (up to 500);
 * - speed: up to 300 when the challenge was done right, shrinking linearly
 *   to nothing at the challenge's speed window;
 * - concept: 200 for naming the kind of attention the challenge used.
 */
class AttentionScore
{
    public const PERFORMANCE_PER_ACCURACY = 5;

    public const SPEED_MAX = 300;

    public const CONCEPT = 200;

    public const ROUND_MAX = 100 * self::PERFORMANCE_PER_ACCURACY + self::SPEED_MAX + self::CONCEPT;

    public static function performance(AttentionType $type, int $accuracy, bool $correct, int $durationMs, ?int $reactionMs): int
    {
        $points = max(0, min(100, $accuracy)) * self::PERFORMANCE_PER_ACCURACY;

        $time = $type->scoresReaction() ? $reactionMs : $durationMs;

        if ($correct && $time !== null) {
            $left = 1 - $time / $type->speedWindowMs();
            $points += (int) round(self::SPEED_MAX * max(0, min(1, $left)));
        }

        return $points;
    }

    public static function concept(bool $guessCorrect): int
    {
        return $guessCorrect ? self::CONCEPT : 0;
    }

    public static function gameMax(): int
    {
        return self::ROUND_MAX * count(AttentionType::cases());
    }
}
