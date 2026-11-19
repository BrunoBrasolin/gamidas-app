<?php

namespace App\Policies;

use App\Models\BankRule;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BankRulePolicy
{
    /**
     * Bank data is private to the member who connected the bank; anyone
     * else gets a 404, as if it did not exist.
     */
    public function view(User $user, BankRule $rule): Response
    {
        return $rule->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, BankRule $rule): Response
    {
        return $this->view($user, $rule);
    }

    public function delete(User $user, BankRule $rule): Response
    {
        return $this->view($user, $rule);
    }
}
