<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'balance',
        'reserved_balance',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'reserved_balance' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    /**
     * Convert a rupee amount (e.g. "100.50", 19.99) to exact integer paise
     * without floating-point precision errors.
     */
    public static function rupeesToPaise(string|int|float $amount): int
    {
        $normalized = trim((string) $amount);

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $normalized)) {
            throw new InvalidArgumentException('Amount must be a valid non-negative number with up to 2 decimal places.');
        }

        $parts = explode('.', $normalized, 2);
        $rupees = (int) $parts[0];
        $paise = isset($parts[1]) ? (int) str_pad($parts[1], 2, '0') : 0;

        return ($rupees * 100) + $paise;
    }

    /**
     * Convert integer paise to exact decimal string (e.g. 10050 -> "100.50").
     */
    public static function paiseToRupees(int $paise): string
    {
        $sign = $paise < 0 ? '-' : '';
        $abs = abs($paise);
        $rupees = intdiv($abs, 100);
        $remainder = $abs % 100;

        return sprintf('%s%d.%02d', $sign, $rupees, $remainder);
    }

    /**
     * Convert integer paise to a formatted INR display string (e.g. "₹1,250.50").
     */
    public static function formatPaise(int $paise): string
    {
        $sign = $paise < 0 ? '-' : '';
        $abs = abs($paise);
        $rupees = intdiv($abs, 100);
        $remainder = $abs % 100;

        return sprintf('%s₹%s.%02d', $sign, number_format($rupees), $remainder);
    }

    public function getFormattedBalanceAttribute(): string
    {
        return self::formatPaise($this->balance);
    }

    public function getFormattedReservedBalanceAttribute(): string
    {
        return self::formatPaise($this->reserved_balance);
    }
}
