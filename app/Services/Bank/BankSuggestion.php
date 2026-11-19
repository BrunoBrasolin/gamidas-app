<?php

namespace App\Services\Bank;

use App\Models\BankRule;
use App\Models\ExpenseOccurrence;

/**
 * What the inbox proposes for a pending transaction: what a rule taught
 * ("PIX to Fulano is the rent") or an expense that clearly matches it (same
 * amount and installment), confirmed with one click.
 */
final readonly class BankSuggestion
{
    public function __construct(
        public CatalogChoice $choice,
        public string $label,
        public ?BankRule $rule = null,
        public ?ExpenseOccurrence $occurrence = null,
    ) {
        //
    }
}
