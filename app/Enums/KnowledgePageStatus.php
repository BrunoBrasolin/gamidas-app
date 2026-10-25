<?php

namespace App\Enums;

/**
 * How far the user is with a knowledge page. Optional and set by hand.
 */
enum KnowledgePageStatus: string
{
    case Unread = 'unread';
    case InProgress = 'in_progress';
    case Reviewed = 'reviewed';
}
