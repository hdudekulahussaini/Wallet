<?php

namespace App\Policies;

use App\Models\BankAccount;
use App\Models\User;

class BankAccountPolicy
{
    public function view(User $user, BankAccount $bankAccount): bool
    {
        return $bankAccount->user_id === $user->id;
    }

    public function verify(User $user, BankAccount $bankAccount): bool
    {
        return $bankAccount->user_id === $user->id;
    }

    public function delete(User $user, BankAccount $bankAccount): bool
    {
        return $bankAccount->user_id === $user->id;
    }
}
