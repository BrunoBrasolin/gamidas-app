<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WatchedItem;
use Illuminate\Auth\Access\Response;

class WatchedItemPolicy
{
    /**
     * The watch history belongs exclusively to its owner.
     */
    public function delete(User $user, WatchedItem $item): Response
    {
        return $item->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
