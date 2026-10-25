<?php

namespace App\Policies;

use App\Models\KnowledgeCategory;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class KnowledgeCategoryPolicy
{
    /**
     * Access follows the subject the category belongs to.
     */
    public function view(User $user, KnowledgeCategory $category): Response
    {
        return $category->subject->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, KnowledgeCategory $category): Response
    {
        return $this->view($user, $category);
    }

    public function delete(User $user, KnowledgeCategory $category): Response
    {
        return $this->view($user, $category);
    }
}
