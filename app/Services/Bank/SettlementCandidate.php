<?php

namespace App\Services\Bank;

use App\Models\ExpenseOccurrence;

/**
 * A household expense a house settlement may pay: who owes the share on it
 * (the member who did not register it) and how much. Already paid when the
 * other member's bank settled it first; the same transfer, seen in this
 * bank, then only links it.
 */
final readonly class SettlementCandidate
{
    public function __construct(
        public ExpenseOccurrence $occurrence,
        public int $payerId,
        public int $shareCents,
        public bool $settledByPartner,
    ) {
        //
    }
}
