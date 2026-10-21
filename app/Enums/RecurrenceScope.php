<?php

namespace App\Enums;

/**
 * Which part of a recurring event an edit or deletion applies to.
 */
enum RecurrenceScope: string
{
    case Occurrence = 'occurrence';
    case Series = 'series';
}
