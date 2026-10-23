<?php

namespace App\Policies;

use App\Models\TimeCategory;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TimeCategoryPolicy
{
    /**
     * Categories and their time logs belong exclusively to their owner.
     */
    public function update(User $user, TimeCategory $category): Response
    {
        return $category->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, TimeCategory $category): Response
    {
        return $this->update($user, $category);
    }
}
