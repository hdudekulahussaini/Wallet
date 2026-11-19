<?php

namespace App\Http\Requests\Wallet;

use App\Models\Wallet;
use Illuminate\Foundation\Http\FormRequest;

class CreateDepositOrderRequest extends FormRequest
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
        $minRupees = (float) Wallet::paiseToRupees((int) config('cashfree.limits.min_deposit_paise', 100));
        $maxRupees = (float) Wallet::paiseToRupees((int) config('cashfree.limits.max_deposit_paise', 10000000));

        return [
            'amount' => [
                'required',
                'numeric',
                'regex:/^\d+(\.\d{1,2})?$/',
                'min:' . $minRupees,
                'max:' . $maxRupees,
            ],
            'phone' => ['nullable', 'digits:10'],
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
