<?php

namespace App\Policies;

use App\Models\TimerSession;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TimerSessionPolicy
{
    /**
     * Tasks, timer sessions and time entries belong exclusively to their owner.
     */
    public function update(User $user, TimerSession $timerSession): Response
    {
        return $timerSession->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, TimerSession $timerSession): Response
    {
        return $this->update($user, $timerSession);
    }
}
