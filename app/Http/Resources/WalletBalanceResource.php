<?php

namespace App\Http\Resources;

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Wallet
 */
class WalletBalanceResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'balance_paise' => $this->balance,
            'reserved_balance_paise' => $this->reserved_balance,
            'balance_rupees' => Wallet::paiseToRupees($this->balance),
            'reserved_balance_rupees' => Wallet::paiseToRupees($this->reserved_balance),
            'formatted_balance' => $this->formatted_balance,
            'formatted_reserved_balance' => $this->formatted_reserved_balance,
        ];
    }
}
