<?php

namespace App\Policies;

use App\Models\BankConnection;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BankConnectionPolicy
{
    /**
     * Bank data is private to the member who connected the bank; anyone
     * else gets a 404, as if it did not exist.
     */
    public function view(User $user, BankConnection $connection): Response
    {
        return $connection->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, BankConnection $connection): Response
    {
        return $this->view($user, $connection);
    }

    public function delete(User $user, BankConnection $connection): Response
    {
        return $this->view($user, $connection);
    }
}
