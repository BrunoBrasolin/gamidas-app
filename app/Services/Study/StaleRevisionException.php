<?php

namespace App\Services\Study;

use App\Models\StudyPage;
use RuntimeException;

/**
 * The page was saved elsewhere (another tab or device) after the version the
 * editor started from, so the autosave would overwrite newer text.
 */
class StaleRevisionException extends RuntimeException
{
    public function __construct(public readonly StudyPage $page)
    {
        parent::__construct('The study page has a newer revision.');
    }
}
