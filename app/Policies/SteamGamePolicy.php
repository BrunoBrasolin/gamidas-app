<?php

namespace App\Policies;

use App\Models\SteamGame;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SteamGamePolicy
{
    /**
     * A Steam library is private to the user who connected the account;
     * anyone else gets a 404, as if it did not exist.
     */
    public function view(User $user, SteamGame $game): Response
    {
        return $game->account->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
