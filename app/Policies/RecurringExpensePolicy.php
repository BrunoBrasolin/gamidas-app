<?php

namespace App\Policies;

use App\Enums\ExpenseScope;
use App\Models\RecurringExpense;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class RecurringExpensePolicy
{
    public function view(User $user, RecurringExpense $recurringExpense): bool
    {
        return $recurringExpense->household_id === $user->householdId()
            && ($recurringExpense->scope === ExpenseScope::Household || $recurringExpense->owner_user_id === $user->id);
    }

    public function update(User $user, RecurringExpense $recurringExpense): Response
    {
        return $this->view($user, $recurringExpense)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, RecurringExpense $recurringExpense): Response
    {
        return $this->update($user, $recurringExpense);
    }
}
