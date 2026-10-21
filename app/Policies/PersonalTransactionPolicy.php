<?php

namespace App\Policies;

use App\Models\PersonalTransaction;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PersonalTransactionPolicy
{
    /**
     * Personal transactions belong exclusively to their owner.
     */
    public function update(User $user, PersonalTransaction $transaction): Response
    {
        return $transaction->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function delete(User $user, PersonalTransaction $transaction): Response
    {
        return $this->update($user, $transaction);
    }
}
