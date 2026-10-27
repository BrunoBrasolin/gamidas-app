<?php

namespace App\Policies;

use App\Models\ComplementaryActivity;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ComplementaryActivityPolicy
{
    /**
     * Complementary activities and their certificates belong exclusively to
     * their owner.
     */
    public function view(User $user, ComplementaryActivity $activity): Response
    {
        return $activity->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, ComplementaryActivity $activity): Response
    {
        return $this->view($user, $activity);
    }

    public function delete(User $user, ComplementaryActivity $activity): Response
    {
        return $this->view($user, $activity);
    }
}
