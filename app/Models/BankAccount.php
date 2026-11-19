<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    use HasFactory;

    public const VERIFICATION_PENDING = 'pending';
    public const VERIFICATION_VERIFIED = 'verified';
    public const VERIFICATION_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'account_holder_name',
        'account_number',
        'account_number_last4',
        'account_number_hash',
        'ifsc_code',
        'bank_name',
        'branch_name',
        'is_verified',
        'verification_status',
        'verification_reference_id',
        'name_at_bank',
        'cashfree_beneficiary_id',
        'verified_at',
    ];

    protected $hidden = [
        'account_number',
        'account_number_hash',
    ];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    public function getMaskedAccountNumberAttribute(): string
    {
        return 'XXXXXX' . $this->account_number_last4;
    }

    public static function makeAccountHash(string $accountNumber, string $ifscCode): string
    {
        $normalized = preg_replace('/\s+/', '', $accountNumber) . '|' . strtoupper(trim($ifscCode));

        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }
}
