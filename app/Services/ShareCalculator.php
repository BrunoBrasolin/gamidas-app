<?php

namespace App\Services;

use App\Enums\ExpenseScope;
use App\Enums\OccurrenceStatus;
use App\Models\ExpenseOccurrence;
use App\Models\ExpenseShare;
use App\Models\Household;
use Illuminate\Support\Facades\Date;
use LogicException;

/**
 * Persists each member's share of household occurrences.
 *
 * Invariant: every pending household occurrence has shares computed from the
 * household's current salaries. Paid occurrences keep the shares they had at
 * payment time and are never recalculated here.
 */
final class ShareCalculator
{
    public function __construct(private readonly ProportionalSplitter $splitter)
    {
        //
    }

    /**
     * Recalculate and store the shares of a single occurrence.
     *
     * @param  array<int, int>|null  $salaries  Salaries keyed by user id; loaded from the household when null.
     */
    public function sync(ExpenseOccurrence $occurrence, ?array $salaries = null): void
    {
        if ($occurrence->scope !== ExpenseScope::Household) {
            $occurrence->shares()->delete();

            return;
        }

        if ($occurrence->status === OccurrenceStatus::Paid) {
            throw new LogicException('Shares of a paid occurrence are historical and cannot be recalculated.');
        }

        $salaries ??= Household::findOrFail($occurrence->household_id)->salaries()->all();

        if ($salaries === []) {
            throw new LogicException('A household needs members before its expenses can be split.');
        }

        $combined = array_sum($salaries);
        $amounts = $this->splitter->split($occurrence->amount_cents, $salaries);
        $now = Date::now();

        $rows = [];

        foreach ($amounts as $userId => $amount) {
            $rows[] = [
                'expense_occurrence_id' => $occurrence->id,
                'user_id' => $userId,
                'amount_cents' => $amount,
                'salary_cents' => $salaries[$userId],
                'combined_salary_cents' => $combined,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        ExpenseShare::upsert(
            $rows,
            ['expense_occurrence_id', 'user_id'],
            ['amount_cents', 'salary_cents', 'combined_salary_cents', 'updated_at'],
        );

        $occurrence->shares()->whereNotIn('user_id', array_keys($amounts))->delete();
        $occurrence->unsetRelation('shares');
    }

    /**
     * Recalculate the shares of every pending household occurrence of the
     * household with its current salaries. Paid occurrences are untouched.
     *
     * @return int The number of occurrences recalculated.
     */
    public function recalculatePending(Household $household): int
    {
        $salaries = $household->salaries()->all();
        $count = 0;

        ExpenseOccurrence::query()
            ->where('household_id', $household->id)
            ->where('scope', ExpenseScope::Household)
            ->where('status', OccurrenceStatus::Pending)
            ->orderBy('id')
            ->chunkById(200, function ($occurrences) use ($salaries, &$count) {
                foreach ($occurrences as $occurrence) {
                    $this->sync($occurrence, $salaries);
                    $count++;
                }
            });

        return $count;
    }
}
