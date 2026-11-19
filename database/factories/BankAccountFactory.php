<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    public function definition(): array
    {
        $accountNumber = (string) fake()->numerify('############');
        $ifscCode = 'HDFC0000123';

        return [
            'user_id' => User::factory(),
            'account_holder_name' => fake()->name(),
            'account_number' => $accountNumber,
            'account_number_last4' => substr($accountNumber, -4),
            'account_number_hash' => BankAccount::makeAccountHash($accountNumber, $ifscCode),
            'ifsc_code' => $ifscCode,
            'bank_name' => 'HDFC Bank',
            'is_verified' => false,
            'verification_status' => BankAccount::VERIFICATION_PENDING,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (): array => [
            'is_verified' => true,
            'verification_status' => BankAccount::VERIFICATION_VERIFIED,
            'verified_at' => now(),
        ]);
    }
}
