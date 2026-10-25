<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgePage;
use RuntimeException;

/**
 * The page was saved elsewhere (another tab or device) after the version the
 * editor started from, so the autosave would overwrite newer text.
 */
class StaleRevisionException extends RuntimeException
{
    public function __construct(public readonly KnowledgePage $page)
    {
        parent::__construct('The knowledge page has a newer revision.');
    }
}
