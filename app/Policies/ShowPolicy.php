<?php

namespace App\Policies;

use App\Models\Show;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ShowPolicy
{
    /**
     * Shows are shared by every member of their household.
     */
    public function view(User $user, Show $show): Response
    {
        return $show->household_id === $user->householdId()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, Show $show): Response
    {
        return $this->view($user, $show);
    }

    public function delete(User $user, Show $show): Response
    {
        return $this->view($user, $show);
    }
}
