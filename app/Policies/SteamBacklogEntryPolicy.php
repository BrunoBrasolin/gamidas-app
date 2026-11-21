<?php

namespace App\Policies;

use App\Models\SteamBacklogEntry;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SteamBacklogEntryPolicy
{
    /**
     * The list is private to its owner; anyone else gets a 404.
     */
    public function update(User $user, SteamBacklogEntry $entry): Response
    {
        return $entry->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, SteamBacklogEntry $entry): Response
    {
        return $this->update($user, $entry);
    }
}
