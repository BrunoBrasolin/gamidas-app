<?php

namespace App\Services;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Updates member salaries and keeps pending expense shares in sync.
 */
final class SalaryService
{
    public function __construct(
        private readonly ShareCalculator $shares,
        private readonly AuditLogger $audit,
    ) {
        //
    }

    /**
     * Update salaries and recalculate every pending household occurrence in a
     * single transaction. Paid occurrences keep their historical shares.
     *
     * @param  array<int, int>  $salaries  New salaries in cents keyed by user id.
     * @return int The number of pending occurrences recalculated.
     */
    public function update(Household $household, array $salaries, User $actor): int
    {
        return DB::transaction(function () use ($household, $salaries, $actor) {
            $members = HouseholdMember::query()
                ->where('household_id', $household->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('user_id');

            $unknown = array_diff(array_keys($salaries), $members->keys()->all());

            if ($unknown !== []) {
                throw ValidationException::withMessages([
                    'salaries' => 'Só é possível alterar salários de membros da residência.',
                ]);
            }

            $old = $members->map(fn (HouseholdMember $member) => $member->monthly_salary_cents)->all();
            $changed = false;

            foreach ($salaries as $userId => $cents) {
                $member = $members[$userId];

                if ($member->monthly_salary_cents === $cents) {
                    continue;
                }

                $member->update(['monthly_salary_cents' => $cents]);
                $changed = true;
            }

            if (! $changed) {
                return 0;
            }

            $recalculated = $this->shares->recalculatePending($household);

            $this->audit->record('salaries.updated', $household, $actor, $household->id, [
                'salaries' => $old,
            ], [
                'salaries' => $members->map(fn (HouseholdMember $member) => $member->monthly_salary_cents)->all(),
                'recalculated_pending_occurrences' => $recalculated,
            ]);

            return $recalculated;
        });
    }
}
