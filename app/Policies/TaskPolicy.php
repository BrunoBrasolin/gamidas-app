<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TaskPolicy
{
    /**
     * Tasks, timer sessions and time entries belong exclusively to their owner.
     */
    public function update(User $user, Task $task): Response
    {
        return $task->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, Task $task): Response
    {
        return $this->update($user, $task);
    }
}
