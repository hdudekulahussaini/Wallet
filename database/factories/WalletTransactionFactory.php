<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WalletTransaction>
 */
class WalletTransactionFactory extends Factory
{
    protected $model = WalletTransaction::class;

    public function definition(): array
    {
        return [
            'wallet_id' => Wallet::factory(),
            'user_id' => User::factory(),
            'type' => WalletTransaction::TYPE_CREDIT,
            'amount' => 10000,
            'reference_id' => 'ORD_' . now()->format('YmdHis') . '_' . strtoupper(Str::random(8)),
            'transaction_type' => WalletTransaction::TXN_DEPOSIT,
            'status' => WalletTransaction::STATUS_PENDING,
            'description' => 'Wallet deposit via Cashfree',
        ];
    }
}
