<?php

namespace App\Policies;

use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ExpenseCategoryPolicy
{
    public function update(User $user, ExpenseCategory $category): Response
    {
        return $category->household_id === $user->householdId()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, ExpenseCategory $category): Response
    {
        return $this->update($user, $category);
    }
}
