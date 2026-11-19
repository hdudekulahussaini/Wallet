<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Withdrawal;

class WithdrawalPolicy
{
    public function view(User $user, Withdrawal $withdrawal): bool
    {
        return $withdrawal->user_id === $user->id;
    }

    public function sync(User $user, Withdrawal $withdrawal): bool
    {
        return $withdrawal->user_id === $user->id;
    }
}
