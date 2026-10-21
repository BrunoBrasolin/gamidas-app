<?php

namespace App\Services;

use App\Enums\PurchaseMode;
use InvalidArgumentException;

/**
 * Computes the amount of each installment of a credit card purchase.
 */
final class InstallmentPlanner
{
    /**
     * For {@see PurchaseMode::Installments}, $amountCents is the total price:
     * every installment gets the rounded-down quotient and the final one
     * absorbs the remainder, so R$ 100,00 in 3x is 33,33 + 33,33 + 33,34.
     *
     * For {@see PurchaseMode::FixedMonthly}, $amountCents is the monthly charge
     * repeated for every installment.
     *
     * @return list<int> Installment amounts in cents, first to last.
     */
    public function plan(PurchaseMode $mode, int $amountCents, int $count): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException('A purchase needs at least one installment.');
        }

        if ($amountCents < 0) {
            throw new InvalidArgumentException('Purchase amounts must not be negative.');
        }

        if ($mode === PurchaseMode::FixedMonthly) {
            return array_fill(0, $count, $amountCents);
        }

        $base = intdiv($amountCents, $count);
        $plan = array_fill(0, $count, $base);
        $plan[$count - 1] = $amountCents - $base * ($count - 1);

        return array_values($plan);
    }

    /**
     * The total price of a purchase given its mode and entered amount.
     */
    public function total(PurchaseMode $mode, int $amountCents, int $count): int
    {
        return array_sum($this->plan($mode, $amountCents, $count));
    }
}
