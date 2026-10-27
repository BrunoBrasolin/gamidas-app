<?php

namespace App\Enums;

/**
 * The kinds of attention the attention game demonstrates. Each one has its
 * own challenge (played in this order); the frontend holds the challenge
 * texts and explanations in resources/js/lib/attention.ts.
 */
enum AttentionType: string
{
    case Selective = 'selective';
    case Sustained = 'sustained';
    case Divided = 'divided';
    case Alternating = 'alternating';
    case Voluntary = 'voluntary';

    public function label(): string
    {
        return match ($this) {
            self::Selective => 'Atenção seletiva',
            self::Sustained => 'Atenção sustentada',
            self::Divided => 'Atenção dividida',
            self::Alternating => 'Atenção alternada',
            self::Voluntary => 'Atenção voluntária',
        };
    }

    public function challenge(): string
    {
        return match ($this) {
            self::Selective => 'Encontre o intruso',
            self::Sustained => 'Fique de olho!',
            self::Divided => 'Duas coisas ao mesmo tempo',
            self::Alternating => 'Mudou a regra!',
            self::Voluntary => 'Leia antes de clicar!',
        };
    }

    /**
     * Whether the speed bonus is measured on the mean reaction time to each
     * stimulus (go/no-go) instead of the time taken to answer.
     */
    public function scoresReaction(): bool
    {
        return $this === self::Alternating;
    }

    /**
     * Time (ms) in which an instant answer earns the whole speed bonus and a
     * slower one earns proportionally less, down to nothing at the limit.
     */
    public function speedWindowMs(): int
    {
        return match ($this) {
            self::Selective => 20_000,
            self::Sustained => 10_000,
            self::Divided => 15_000,
            self::Alternating => 800,
            self::Voluntary => 30_000,
        };
    }
}
