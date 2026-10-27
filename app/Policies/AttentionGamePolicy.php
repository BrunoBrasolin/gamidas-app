<?php

namespace App\Policies;

use App\Models\AttentionGame;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AttentionGamePolicy
{
    /**
     * Rooms and their reports are shared by every member of their household.
     */
    public function view(User $user, AttentionGame $game): Response
    {
        return $game->household_id === $user->householdId()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, AttentionGame $game): Response
    {
        return $this->view($user, $game);
    }

    public function delete(User $user, AttentionGame $game): Response
    {
        return $this->view($user, $game);
    }
}
