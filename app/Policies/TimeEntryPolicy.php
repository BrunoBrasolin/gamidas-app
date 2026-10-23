<?php

namespace App\Policies;

use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TimeEntryPolicy
{
    /**
     * Tasks, timer sessions and time entries belong exclusively to their owner.
     */
    public function update(User $user, TimeEntry $timeEntry): Response
    {
        return $timeEntry->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, TimeEntry $timeEntry): Response
    {
        return $this->update($user, $timeEntry);
    }
}
