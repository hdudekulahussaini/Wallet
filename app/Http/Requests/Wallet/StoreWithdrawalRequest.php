<?php

namespace App\Http\Requests\Wallet;

use App\Models\Wallet;
use Illuminate\Foundation\Http\FormRequest;

class StoreWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $minRupees = (float) Wallet::paiseToRupees((int) config('cashfree.limits.min_withdrawal_paise', 100));
        $maxRupees = (float) Wallet::paiseToRupees((int) config('cashfree.limits.max_withdrawal_paise', 10000000));

        return [
            'bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'],
            'amount' => [
                'required',
                'numeric',
                'regex:/^\d+(\.\d{1,2})?$/',
                'min:' . $minRupees,
                'max:' . $maxRupees,
            ],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'Amount can have at most 2 decimal places.',
        ];
    }
}
