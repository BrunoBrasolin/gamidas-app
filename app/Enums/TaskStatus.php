<?php

namespace App\Enums;

/**
 * Derived from a task's completed_at / archived_at timestamps; archived wins.
 */
enum TaskStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Archived = 'archived';
}
