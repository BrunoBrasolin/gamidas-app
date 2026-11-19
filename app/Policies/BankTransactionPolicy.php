<?php

namespace App\Policies;

use App\Models\BankTransaction;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BankTransactionPolicy
{
    /**
     * Bank data is private to the member who connected the bank; anyone
     * else gets a 404, as if it did not exist.
     */
    public function view(User $user, BankTransaction $transaction): Response
    {
        return $transaction->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, BankTransaction $transaction): Response
    {
        return $this->view($user, $transaction);
    }

    public function delete(User $user, BankTransaction $transaction): Response
    {
        return $this->view($user, $transaction);
    }
}
