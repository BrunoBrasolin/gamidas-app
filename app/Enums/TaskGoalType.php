<?php

namespace App\Enums;

/**
 * What a task aims for: a number of recorded hours, an outcome, both, or
 * nothing (a plain activity that is only tracked).
 */
enum TaskGoalType: string
{
    case None = 'none';
    case Time = 'time';
    case Outcome = 'outcome';
    case TimeAndOutcome = 'time_and_outcome';

    public function hasTimeTarget(): bool
    {
        return $this === self::Time || $this === self::TimeAndOutcome;
    }

    public function hasOutcome(): bool
    {
        return $this === self::Outcome || $this === self::TimeAndOutcome;
    }
}
