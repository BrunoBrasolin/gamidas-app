<?php

namespace App\Policies;

use App\Models\RoulettePlace;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class RoulettePlacePolicy
{
    /**
     * The roulette options are shared by every member of the household.
     */
    public function update(User $user, RoulettePlace $place): Response
    {
        return $this->delete($user, $place);
    }

    public function delete(User $user, RoulettePlace $place): Response
    {
        return $place->household_id === $user->householdId()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
