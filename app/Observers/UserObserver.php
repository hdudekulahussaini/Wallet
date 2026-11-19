<?php

namespace App\Observers;

use App\Models\User;
use App\Models\Wallet;

class UserObserver
{
    /**
     * Automatically provision a zero-balance wallet when a user is created.
     */
    public function created(User $user): void
    {
        Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0, 'reserved_balance' => 0]
        );
    }
}
