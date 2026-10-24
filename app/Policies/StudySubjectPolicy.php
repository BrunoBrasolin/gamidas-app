<?php

namespace App\Policies;

use App\Models\StudySubject;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class StudySubjectPolicy
{
    /**
     * The study library is private: subjects belong to whoever created them.
     */
    public function view(User $user, StudySubject $subject): Response
    {
        return $subject->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, StudySubject $subject): Response
    {
        return $this->view($user, $subject);
    }

    public function delete(User $user, StudySubject $subject): Response
    {
        return $this->view($user, $subject);
    }
}
