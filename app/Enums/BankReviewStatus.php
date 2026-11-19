<?php

namespace App\Enums;

/**
 * Where an imported transaction stands in the inbox.
 */
enum BankReviewStatus: string
{
    case Pending = 'pending';
    case Catalogued = 'catalogued';
    case Ignored = 'ignored';
}
