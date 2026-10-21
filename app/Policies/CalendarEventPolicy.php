<?php

namespace App\Policies;

use App\Models\CalendarEvent;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CalendarEventPolicy
{
    /**
     * Calendar events are shared by every member of their household.
     */
    public function update(User $user, CalendarEvent $event): Response
    {
        return $event->household_id === $user->householdId()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, CalendarEvent $event): Response
    {
        return $this->update($user, $event);
    }
}
