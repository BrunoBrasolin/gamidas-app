<?php

namespace App\Services;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Creates households and adds members to them.
 */
final class HouseholdService
{
    public function __construct(
        private readonly ShareCalculator $shares,
        private readonly AuditLogger $audit,
    ) {
        //
    }

    public function create(string $name): Household
    {
        return DB::transaction(function () use ($name) {
            $household = Household::create(['name' => $name]);

            foreach ((array) config('finance.default_categories') as $category) {
                $household->categories()->create(['name' => $category]);
            }

            return $household;
        });
    }

    /**
     * Create a verified user account and add it to the household. Pending
     * household expenses are re-split to include the new member.
     */
    public function addMember(
        Household $household,
        string $name,
        string $email,
        string $password,
        string $preposition,
        int $salaryCents,
    ): HouseholdMember {
        return DB::transaction(function () use ($household, $name, $email, $password, $preposition, $salaryCents) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $user->forceFill(['email_verified_at' => Date::now()])->save();

            $member = $household->members()->create([
                'user_id' => $user->id,
                'monthly_salary_cents' => $salaryCents,
                'payment_label_preposition' => $preposition,
            ]);

            $recalculated = $this->shares->recalculatePending($household);

            $this->audit->record('household.member_added', $member, null, $household->id, null, [
                'user_id' => $user->id,
                'monthly_salary_cents' => $salaryCents,
                'recalculated_pending_occurrences' => $recalculated,
            ]);

            return $member;
        });
    }
}
