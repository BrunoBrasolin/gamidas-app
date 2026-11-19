<?php

namespace App\Services\Bank;

use Carbon\CarbonImmutable;

/**
 * What the owner decided for an imported transaction.
 *
 * Actions: household, personal, income, investment (a new record), link (an
 * expense that already exists, e.g. this month's rent), ignore, offset
 * (cancel out with another transaction: a refund and its charge), or
 * settlement (a transfer between the members that pays their shares of
 * several household expenses, the "Acerto da casa"), or split (the value
 * divided into parts, each catalogued on its own: part an existing expense,
 * part a personal one). Title and date default to the transaction's name
 * and reference date. Remembering creates a rule for the counterparty, for
 * any value or only for this one; a split is never remembered.
 */
final readonly class CatalogChoice
{
    public const ACTIONS = ['household', 'personal', 'income', 'investment', 'link', 'ignore', 'offset', 'settlement', 'split'];

    /**
     * Parts a transaction may be split into.
     */
    public const MAX_PARTS = 10;

    /**
     * @param  list<int>  $occurrenceIds  The household expenses a settlement pays.
     * @param  list<SplitPart>  $parts  The parts of a split, adding up to the transaction.
     */
    public function __construct(
        public string $action,
        public ?string $title = null,
        public ?CarbonImmutable $date = null,
        public ?string $notes = null,
        public ?int $occurrenceId = null,
        public bool $remember = true,
        public ?bool $autoApply = null,
        public bool $rememberAmount = false,
        public ?int $offsetTransactionId = null,
        public array $occurrenceIds = [],
        public array $parts = [],
    ) {
        //
    }
}
