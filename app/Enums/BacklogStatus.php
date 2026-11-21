<?php

namespace App\Enums;

/**
 * Where a game of the "para zerar" list is: waiting its turn, being played
 * or finished.
 */
enum BacklogStatus: string
{
    case Queued = 'queued';
    case Playing = 'playing';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Na fila',
            self::Playing => 'Jogando',
            self::Completed => 'Zerado',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
