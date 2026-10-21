<?php

namespace App\Enums;

enum RecurringKind: string
{
    /** Same amount every month, indefinitely or until an optional end month. */
    case Fixed = 'fixed';

    /** Same amount every month for a fixed number of months. */
    case Temporary = 'temporary';
}
