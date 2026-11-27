<?php

namespace App\Enums;

/**
 * The tier lists a user keeps, each of which can have a public page.
 */
enum TierListKind: string
{
    case Games = 'games';
    case Shows = 'shows';

    /** What the list ranks, for the title of its public page. */
    public function subject(): string
    {
        return match ($this) {
            self::Games => 'jogos',
            self::Shows => 'shows',
        };
    }
}
