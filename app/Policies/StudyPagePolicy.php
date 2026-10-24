<?php

namespace App\Policies;

use App\Models\StudyPage;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class StudyPagePolicy
{
    /**
     * Access follows the subject the page belongs to.
     */
    public function view(User $user, StudyPage $page): Response
    {
        return $page->subject->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, StudyPage $page): Response
    {
        return $this->view($user, $page);
    }

    public function delete(User $user, StudyPage $page): Response
    {
        return $this->view($user, $page);
    }
}
