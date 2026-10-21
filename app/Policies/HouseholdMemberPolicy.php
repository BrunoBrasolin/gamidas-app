<?php

namespace App\Policies;

use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class HouseholdMemberPolicy
{
    public function update(User $user, HouseholdMember $member): Response
    {
        return $member->household_id === $user->householdId()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
