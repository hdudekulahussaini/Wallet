<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    use HasFactory;

    public const TYPE_CREDIT = 'credit';
    public const TYPE_DEBIT = 'debit';

    public const TXN_DEPOSIT = 'deposit';
    public const TXN_WITHDRAWAL = 'withdrawal';
    public const TXN_WITHDRAWAL_REVERSAL = 'withdrawal_reversal';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'wallet_id',
        'user_id',
        'type',
        'amount',
        'reference_id',
        'gateway_reference_id',
        'payment_session_id',
        'transaction_type',
        'status',
        'description',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getFormattedAmountAttribute(): string
    {
        return Wallet::formatPaise($this->amount);
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED => 'bg-success',
            self::STATUS_PROCESSING => 'bg-info text-dark',
            self::STATUS_PENDING => 'bg-warning text-dark',
            self::STATUS_FAILED => 'bg-danger',
            default => 'bg-secondary',
        };
    }
}
