<?php

namespace App\Services\Bank;

/**
 * One part of a transaction whose value is split ("Dividir valor"): how
 * much of it goes where. Actions: household (a new household expense), link
 * (an expense that already exists), personal or investment (an entry in the
 * owner's personal ledger). The title defaults to the transaction's name.
 */
final readonly class SplitPart
{
    public const ACTIONS = ['household', 'link', 'personal', 'investment'];

    public function __construct(
        public string $action,
        public int $amountCents,
        public ?string $title = null,
        public ?int $occurrenceId = null,
    ) {
        //
    }
}
