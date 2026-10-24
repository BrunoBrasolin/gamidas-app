<?php

namespace App\Enums;

/**
 * How far the user is with a study page. Optional and set by hand.
 */
enum StudyPageStatus: string
{
    case Unread = 'unread';
    case InProgress = 'in_progress';
    case Reviewed = 'reviewed';
}
