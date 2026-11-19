<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Withdrawal>
 */
class WithdrawalFactory extends Factory
{
    protected $model = Withdrawal::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'wallet_id' => Wallet::factory(),
            'bank_account_id' => BankAccount::factory()->verified(),
            'amount' => 10000,
            'transfer_id' => 'WDR_' . now()->format('YmdHis') . '_' . strtoupper(Str::random(8)),
            'idempotency_key' => (string) Str::uuid(),
            'status' => Withdrawal::STATUS_PENDING,
        ];
    }
}
