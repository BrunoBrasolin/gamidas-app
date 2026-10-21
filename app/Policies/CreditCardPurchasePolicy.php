<?php

namespace App\Policies;

use App\Enums\ExpenseScope;
use App\Models\CreditCardPurchase;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CreditCardPurchasePolicy
{
    public function view(User $user, CreditCardPurchase $purchase): bool
    {
        return $purchase->household_id === $user->householdId()
            && ($purchase->scope === ExpenseScope::Household || $purchase->owner_user_id === $user->id);
    }

    public function update(User $user, CreditCardPurchase $purchase): Response
    {
        return $this->view($user, $purchase)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, CreditCardPurchase $purchase): Response
    {
        return $this->update($user, $purchase);
    }
}
