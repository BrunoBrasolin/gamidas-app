<?php

namespace App\Policies;

use App\Models\KnowledgePage;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class KnowledgePagePolicy
{
    /**
     * Access follows the subject the page belongs to.
     */
    public function view(User $user, KnowledgePage $page): Response
    {
        return $page->subject->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, KnowledgePage $page): Response
    {
        return $this->view($user, $page);
    }

    public function delete(User $user, KnowledgePage $page): Response
    {
        return $this->view($user, $page);
    }
}
