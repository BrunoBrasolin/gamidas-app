<?php

namespace App\Services;

use InvalidArgumentException;
use OverflowException;

/**
 * Splits an amount in cents proportionally to a set of weights (salaries).
 *
 * Each participant receives floor(amount × weight / total weight). The cents
 * left over by rounding down (always fewer than the number of participants)
 * are handed out one at a time using the largest remainder method, so the
 * shares always add up exactly to the original amount.
 *
 * Ties are broken deterministically: larger remainder first, then larger
 * weight, then the participant's position in the input.
 *
 * When every weight is zero (no salaries configured) the amount is split
 * equally, using the same remainder rule.
 */
final class ProportionalSplitter
{
    /**
     * @param  array<int, int>  $weights  Weights keyed by participant id, in tie-break order.
     * @return array<int, int> Shares in cents keyed by participant id, in input order.
     */
    public function split(int $amountCents, array $weights): array
    {
        if ($weights === []) {
            throw new InvalidArgumentException('At least one participant is required to split an amount.');
        }

        if ($amountCents < 0) {
            throw new InvalidArgumentException('Only non-negative amounts can be split.');
        }

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new InvalidArgumentException('Weights must not be negative.');
            }
        }

        $total = array_sum($weights);

        if ($total === 0) {
            $weights = array_map(fn () => 1, $weights);
            $total = count($weights);
        }

        $shares = [];
        $remainders = [];
        $position = 0;

        foreach ($weights as $id => $weight) {
            if ($weight !== 0 && $amountCents > intdiv(PHP_INT_MAX, $weight)) {
                throw new OverflowException('Amount and weight are too large to split safely.');
            }

            $product = $amountCents * $weight;
            $shares[$id] = intdiv($product, $total);
            $remainders[] = ['id' => $id, 'remainder' => $product % $total, 'weight' => $weight, 'position' => $position++];
        }

        $leftover = $amountCents - array_sum($shares);

        usort($remainders, fn (array $a, array $b) => [$b['remainder'], $b['weight'], $a['position']]
            <=> [$a['remainder'], $a['weight'], $b['position']]);

        for ($i = 0; $i < $leftover; $i++) {
            $shares[$remainders[$i]['id']]++;
        }

        return $shares;
    }

    /**
     * The percentage of each weight with two decimals, as basis points
     * (8000 = 80.00%). Rounding remainders are assigned like {@see split()},
     * so the result always totals exactly 10000.
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    public function basisPoints(array $weights): array
    {
        return $this->split(10_000, $weights);
    }
}
