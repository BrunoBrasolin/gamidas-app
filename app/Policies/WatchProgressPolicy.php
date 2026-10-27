<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WatchProgress;
use Illuminate\Auth\Access\Response;

class WatchProgressPolicy
{
    /**
     * What the household is watching is shared by every member.
     */
    public function view(User $user, WatchProgress $progress): Response
    {
        return $progress->household_id === $user->householdId()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, WatchProgress $progress): Response
    {
        return $this->view($user, $progress);
    }
}
