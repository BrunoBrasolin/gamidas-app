<?php

namespace App\Policies;

use App\Models\KnowledgeSubject;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class KnowledgeSubjectPolicy
{
    /**
     * The knowledge base is private: subjects belong to whoever created them.
     */
    public function view(User $user, KnowledgeSubject $subject): Response
    {
        return $subject->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, KnowledgeSubject $subject): Response
    {
        return $this->view($user, $subject);
    }

    public function delete(User $user, KnowledgeSubject $subject): Response
    {
        return $this->view($user, $subject);
    }
}
