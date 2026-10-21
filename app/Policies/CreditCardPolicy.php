<?php

namespace App\Policies;

use App\Models\CreditCard;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CreditCardPolicy
{
    public function update(User $user, CreditCard $creditCard): Response
    {
        return $creditCard->household_id === $user->householdId()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, CreditCard $creditCard): Response
    {
        return $this->update($user, $creditCard);
    }
}
