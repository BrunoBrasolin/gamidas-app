<?php

namespace App\Enums;

enum OccurrenceSource: string
{
    case OneOff = 'one_off';
    case Recurring = 'recurring';
}
