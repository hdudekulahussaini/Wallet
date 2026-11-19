<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Withdrawal extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'user_id',
        'wallet_id',
        'bank_account_id',
        'wallet_transaction_id',
        'amount',
        'transfer_id',
        'idempotency_key',
        'cashfree_reference_id',
        'utr',
        'status',
        'failure_reason',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_REVERSED,
        ], true);
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
            self::STATUS_FAILED, self::STATUS_REVERSED => 'bg-danger',
            default => 'bg-secondary',
        };
    }
}
