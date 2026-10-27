<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WatchedItem;
use Illuminate\Auth\Access\Response;

class WatchedItemPolicy
{
    /**
     * The watch history is shared by every member of the household.
     */
    public function view(User $user, WatchedItem $item): Response
    {
        return $item->household_id === $user->householdId()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, WatchedItem $item): Response
    {
        return $this->view($user, $item);
    }
}
