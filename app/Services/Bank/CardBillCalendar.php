<?php

namespace App\Services\Bank;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use Carbon\CarbonImmutable;

/**
 * Which bill of the credit card a purchase is paid with. A bill closes some
 * days before its due date and takes every purchase since the previous
 * closing: with the bill due on the 10th and closing on the 3rd, a purchase
 * on 12/11 is paid on 10/12, and one on 02/11 on 10/11.
 *
 * The bank says the bill of a purchase only after it closes (and some
 * connections never do), so the cycle is projected month by month from the
 * bills it knows and the card's current due date. When the bank leaves out
 * the closing date, the bill closes a week before it is due (as Nubank's).
 * An installment is paid one month after the other: the first with the bill
 * of the purchase, the third two bills later.
 */
final class CardBillCalendar
{
    /**
     * Days from closing to due date when the bank does not say.
     */
    public const DEFAULT_CLOSING_DAYS = 7;

    /**
     * A closing this far (or farther) from the due date is not believed.
     */
    private const MAX_CLOSING_DAYS = 25;

    /**
     * Known cycles per card account, loaded once.
     *
     * @var array<int, list<array{closing: CarbonImmutable, due: CarbonImmutable}>>
     */
    private array $cycles = [];

    /**
     * The due date of the bill that pays a card purchase, or null when the
     * card has no bill nor due date to go by.
     */
    public function dueDate(BankTransaction $transaction): ?CarbonImmutable
    {
        if ($transaction->bill !== null) {
            return $transaction->bill->due_date;
        }

        $cycles = $this->cycles[$transaction->account->id] ??= $this->cycles($transaction->account);

        if ($cycles === []) {
            return null;
        }

        // Installments of an older purchase come with the purchase date.
        $installment = max(1, $transaction->installment_number ?? 1);
        $purchase = $installment > 1 ? ($transaction->purchase_date ?? $transaction->date) : $transaction->date;

        // The nearest known bill closing on or after the purchase, then whole
        // months until the purchase is after the previous closing and up to
        // this one (bills the bank never sent are skipped this way too).
        $anchor = null;

        foreach ($cycles as $cycle) {
            if ($cycle['closing']->gte($purchase)) {
                $anchor = $cycle;
                break;
            }
        }

        $anchor ??= $cycles[array_key_last($cycles)];
        $months = 0;

        for ($step = 0; $step < 600 && $purchase->gt($anchor['closing']->addMonthsNoOverflow($months)); $step++) {
            $months++;
        }

        for ($step = 0; $step < 600 && $purchase->lte($anchor['closing']->addMonthsNoOverflow($months - 1)); $step++) {
            $months--;
        }

        return $anchor['due']->addMonthsNoOverflow($months + $installment - 1);
    }

    /**
     * The bills the bank sent and the card's current one, by closing date.
     *
     * @return list<array{closing: CarbonImmutable, due: CarbonImmutable}>
     */
    private function cycles(BankAccount $account): array
    {
        $bills = $account->bills()->orderByDesc('due_date')->get(['id', 'closing_date', 'due_date']);
        $current = $account->balance_due_date;

        $days = null;

        foreach ($bills as $bill) {
            if ($bill->closing_date !== null && ($days = $this->closingDays($bill->closing_date, $bill->due_date)) !== null) {
                break;
            }
        }

        if ($days === null && $current !== null && $account->balance_close_date !== null) {
            $days = $this->closingDays($account->balance_close_date, $current);
        }

        $days ??= self::DEFAULT_CLOSING_DAYS;
        $cycles = [];

        foreach ($bills as $bill) {
            $closing = $bill->closing_date !== null && $this->closingDays($bill->closing_date, $bill->due_date) !== null
                ? $this->closingBefore($bill->closing_date, $bill->due_date)
                : $bill->due_date->subDays($days);

            $cycles[] = ['closing' => $closing, 'due' => $bill->due_date];
        }

        if ($current !== null) {
            $cycles[] = ['closing' => $current->subDays($days), 'due' => $current];
        }

        usort($cycles, fn (array $a, array $b) => $a['closing'] <=> $b['closing']);

        return $cycles;
    }

    /**
     * Days from the closing to the due date of the same bill, or null when
     * they do not look like one. The card's closing date may be the one of
     * the bill still open, after its due date: any closing counts as the last
     * one before the due date on its day of the month.
     */
    private function closingDays(CarbonImmutable $closing, CarbonImmutable $due): ?int
    {
        $days = (int) $this->closingBefore($closing, $due)->diffInDays($due);

        return $days >= 1 && $days < self::MAX_CLOSING_DAYS ? $days : null;
    }

    private function closingBefore(CarbonImmutable $closing, CarbonImmutable $due): CarbonImmutable
    {
        for ($step = 0; $step < 600 && $closing->gte($due); $step++) {
            $closing = $closing->subMonthsNoOverflow(1);
        }

        for ($step = 0; $step < 600 && $closing->lt($due->subMonthsNoOverflow(1)); $step++) {
            $closing = $closing->addMonthsNoOverflow(1);
        }

        return $closing;
    }
}
