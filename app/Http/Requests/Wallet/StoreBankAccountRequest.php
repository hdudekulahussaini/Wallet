<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class StoreBankAccountRequest extends FormRequest
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
        return [
            'account_holder_name' => ['required', 'string', 'min:2', 'max:150', 'regex:/^[a-zA-Z\s.\'-]+$/'],
            'account_number' => ['required', 'digits_between:9,18', 'confirmed'],
            'ifsc_code' => ['required', 'string', 'size:11', 'regex:/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/'],
            'bank_name' => ['required', 'string', 'min:2', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_holder_name.regex' => 'Account holder name may only contain letters, spaces, dots, apostrophes, and hyphens.',
            'account_number.digits_between' => 'Bank account number must be between 9 and 18 digits.',
            'account_number.confirmed' => 'Account number confirmation does not match.',
            'ifsc_code.regex' => 'Enter a valid 11-character Indian IFSC code (e.g., HDFC0000123).',
        ];
    }
}
