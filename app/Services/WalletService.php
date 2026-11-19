<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class WalletService
{
    public function getOrCreateWallet(User $user): Wallet
    {
        return $user->getOrCreateWallet();
    }

    /**
     * Collect aggregated metrics and recent activity for the wallet dashboard.
     */
    public function getDashboardSummary(User $user): array
    {
        $wallet = $this->getOrCreateWallet($user);

        $totalAddedPaise = (int) WalletTransaction::where('wallet_id', $wallet->id)
            ->where('type', WalletTransaction::TYPE_CREDIT)
            ->where('transaction_type', WalletTransaction::TXN_DEPOSIT)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->sum('amount');

        $totalWithdrawnPaise = (int) Withdrawal::where('wallet_id', $wallet->id)
            ->where('status', Withdrawal::STATUS_COMPLETED)
            ->sum('amount');

        $pendingWithdrawalsCount = Withdrawal::where('wallet_id', $wallet->id)
            ->whereIn('status', [Withdrawal::STATUS_PENDING, Withdrawal::STATUS_PROCESSING])
            ->count();

        $verifiedBankAccountsCount = BankAccount::where('user_id', $user->id)
            ->where('is_verified', true)
            ->count();

        $totalBankAccountsCount = BankAccount::where('user_id', $user->id)->count();

        $recentTransactions = WalletTransaction::where('wallet_id', $wallet->id)
            ->latest('id')
            ->limit(8)
            ->get();

        $recentWithdrawals = Withdrawal::with('bankAccount')
            ->where('wallet_id', $wallet->id)
            ->latest('id')
            ->limit(5)
            ->get();

        return [
            'wallet' => $wallet,
            'available_balance_paise' => $wallet->balance,
            'reserved_balance_paise' => $wallet->reserved_balance,
            'total_added_paise' => $totalAddedPaise,
            'total_withdrawn_paise' => $totalWithdrawnPaise,
            'pending_withdrawals_count' => $pendingWithdrawalsCount,
            'verified_bank_accounts_count' => $verifiedBankAccountsCount,
            'total_bank_accounts_count' => $totalBankAccountsCount,
            'recent_transactions' => $recentTransactions,
            'recent_withdrawals' => $recentWithdrawals,
        ];
    }

    /**
     * Create a pending deposit transaction before calling Cashfree Create Order.
     */
    public function createPendingDeposit(User $user, int $amountPaise, ?string $description = null): WalletTransaction
    {
        $minPaise = (int) config('cashfree.limits.min_deposit_paise', 100);
        $maxPaise = (int) config('cashfree.limits.max_deposit_paise', 10000000);

        if ($amountPaise < $minPaise || $amountPaise > $maxPaise) {
            throw new InvalidArgumentException(sprintf(
                'Deposit amount must be between %s and %s.',
                Wallet::formatPaise($minPaise),
                Wallet::formatPaise($maxPaise)
            ));
        }

        $wallet = $this->getOrCreateWallet($user);
        $orderId = 'ORD_' . now()->format('YmdHis') . '_' . strtoupper(Str::random(8));

        return WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'type' => WalletTransaction::TYPE_CREDIT,
            'amount' => $amountPaise,
            'reference_id' => $orderId,
            'transaction_type' => WalletTransaction::TXN_DEPOSIT,
            'status' => WalletTransaction::STATUS_PENDING,
            'description' => $description ?? 'Add money to wallet via Cashfree',
        ]);
    }

    /**
     * Store Cashfree payment session and gateway order reference on pending transaction.
     */
    public function attachPaymentSession(
        WalletTransaction $transaction,
        string $paymentSessionId,
        ?string $cfOrderId = null,
        array $metadata = []
    ): WalletTransaction {
        $transaction->update([
            'payment_session_id' => $paymentSessionId,
            'gateway_reference_id' => $cfOrderId ?? $transaction->gateway_reference_id,
            'metadata' => array_merge($transaction->metadata ?? [], $metadata),
        ]);

        return $transaction->refresh();
    }

    /**
     * Confirm a deposit transaction strictly from server-side Cashfree order verification.
     * Credits wallet at most once (idempotent via database transaction + row locking).
     */
    public function confirmDeposit(string $orderId, array $cashfreeOrderData, array $paymentsData = []): WalletTransaction
    {
        return DB::transaction(function () use ($orderId, $cashfreeOrderData, $paymentsData): WalletTransaction {
            /** @var WalletTransaction|null $transaction */
            $transaction = WalletTransaction::where('reference_id', $orderId)
                ->where('transaction_type', WalletTransaction::TXN_DEPOSIT)
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                throw new RuntimeException('Deposit transaction not found for order: ' . $orderId);
            }

            // Idempotency guard: never credit more than once
            if ($transaction->status === WalletTransaction::STATUS_COMPLETED) {
                return $transaction;
            }

            $orderStatus = strtoupper((string) ($cashfreeOrderData['order_status'] ?? ''));
            $cfOrderId = isset($cashfreeOrderData['cf_order_id']) ? (string) $cashfreeOrderData['cf_order_id'] : $transaction->gateway_reference_id;

            // Find a confirmed payment record if available
            $successfulPayment = null;
            $latestPayment = null;
            foreach ($paymentsData as $payment) {
                if (! is_array($payment)) {
                    continue;
                }
                $latestPayment = $payment;
                if (strtoupper((string) ($payment['payment_status'] ?? '')) === 'SUCCESS') {
                    $successfulPayment = $payment;
                    break;
                }
            }

            if ($orderStatus === 'PAID') {
                // Verify paid amount matches expected amount in integer paise
                if (isset($cashfreeOrderData['order_amount']) && is_numeric($cashfreeOrderData['order_amount'])) {
                    $paidPaise = Wallet::rupeesToPaise(
                        number_format((float) $cashfreeOrderData['order_amount'], 2, '.', '')
                    );
                    if ($paidPaise !== $transaction->amount) {
                        Log::critical('Cashfree order amount mismatch during deposit confirmation', [
                            'order_id' => $orderId,
                            'expected_paise' => $transaction->amount,
                            'received_paise' => $paidPaise,
                        ]);
                        throw new RuntimeException('Payment amount mismatch detected during server verification.');
                    }
                }

                /** @var Wallet $wallet */
                $wallet = Wallet::where('id', $transaction->wallet_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $wallet->balance += $transaction->amount;
                $wallet->save();

                $transaction->status = WalletTransaction::STATUS_COMPLETED;
                $transaction->gateway_reference_id = isset($successfulPayment['cf_payment_id'])
                    ? (string) $successfulPayment['cf_payment_id']
                    : $cfOrderId;
                $transaction->metadata = array_merge($transaction->metadata ?? [], [
                    'cf_order_id' => $cfOrderId,
                    'order_status' => $orderStatus,
                    'cf_payment_id' => $successfulPayment['cf_payment_id'] ?? null,
                    'payment_group' => $successfulPayment['payment_group'] ?? null,
                    'bank_reference' => $successfulPayment['bank_reference'] ?? null,
                    'confirmed_at' => now()->toIso8601String(),
                ]);
                $transaction->save();

                return $transaction;
            }

            $latestPaymentStatus = strtoupper((string) ($latestPayment['payment_status'] ?? ''));
            $isFailedOrder = in_array($orderStatus, ['EXPIRED', 'TERMINATED'], true)
                || in_array($latestPaymentStatus, ['FAILED', 'USER_DROPPED', 'CANCELLED', 'VOID'], true);

            if ($isFailedOrder && $transaction->status !== WalletTransaction::STATUS_FAILED) {
                $transaction->status = WalletTransaction::STATUS_FAILED;
                $transaction->gateway_reference_id = $cfOrderId;
                $transaction->metadata = array_merge($transaction->metadata ?? [], [
                    'cf_order_id' => $cfOrderId,
                    'order_status' => $orderStatus,
                    'payment_status' => $latestPaymentStatus ?: null,
                    'failure_reason' => $latestPayment['payment_message'] ?? ('Order status: ' . ($orderStatus ?: 'FAILED')),
                    'failed_at' => now()->toIso8601String(),
                ]);
                $transaction->save();
            }

            return $transaction;
        });
    }

    /**
     * Mark a pending deposit as failed (never overwrites a completed transaction).
     */
    public function markDepositFailed(string $orderId, string $reason, array $metadata = []): ?WalletTransaction
    {
        return DB::transaction(function () use ($orderId, $reason, $metadata): ?WalletTransaction {
            /** @var WalletTransaction|null $transaction */
            $transaction = WalletTransaction::where('reference_id', $orderId)
                ->where('transaction_type', WalletTransaction::TXN_DEPOSIT)
                ->lockForUpdate()
                ->first();

            if (! $transaction || $transaction->status === WalletTransaction::STATUS_COMPLETED) {
                return $transaction;
            }

            $transaction->status = WalletTransaction::STATUS_FAILED;
            $transaction->metadata = array_merge($transaction->metadata ?? [], $metadata, [
                'failure_reason' => $reason,
                'failed_at' => now()->toIso8601String(),
            ]);
            $transaction->save();

            return $transaction;
        });
    }

    /**
     * Reserve wallet balance using a database transaction and row lock,
     * creating the Withdrawal and pending debit WalletTransaction atomically.
     *
     * @return array{withdrawal: Withdrawal, is_duplicate: bool}
     */
    public function reserveWithdrawal(
        User $user,
        BankAccount $bankAccount,
        int $amountPaise,
        ?string $idempotencyKey = null
    ): array {
        $minPaise = (int) config('cashfree.limits.min_withdrawal_paise', 100);
        $maxPaise = (int) config('cashfree.limits.max_withdrawal_paise', 10000000);

        if ($amountPaise < $minPaise || $amountPaise > $maxPaise) {
            throw new InvalidArgumentException(sprintf(
                'Withdrawal amount must be between %s and %s.',
                Wallet::formatPaise($minPaise),
                Wallet::formatPaise($maxPaise)
            ));
        }

        if ($bankAccount->user_id !== $user->id) {
            throw new RuntimeException('Selected bank account does not belong to your account.');
        }

        if (! $bankAccount->is_verified) {
            throw new RuntimeException('Selected bank account must be verified before initiating a withdrawal.');
        }

        $walletRecord = $this->getOrCreateWallet($user);

        return DB::transaction(function () use ($user, $walletRecord, $bankAccount, $amountPaise, $idempotencyKey): array {
            if (filled($idempotencyKey)) {
                $existing = Withdrawal::where('idempotency_key', $idempotencyKey)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return [
                        'withdrawal' => $existing,
                        'is_duplicate' => true,
                    ];
                }
            }

            /** @var Wallet $wallet */
            $wallet = Wallet::where('id', $walletRecord->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($wallet->balance < $amountPaise) {
                throw new RuntimeException(sprintf(
                    'Insufficient wallet balance. Available: %s, Requested: %s.',
                    Wallet::formatPaise($wallet->balance),
                    Wallet::formatPaise($amountPaise)
                ));
            }

            // Reserve balance atomically (prevents negative balances and double spending)
            $wallet->balance -= $amountPaise;
            $wallet->reserved_balance += $amountPaise;
            $wallet->save();

            $transferId = 'WDR_' . now()->format('YmdHis') . '_' . strtoupper(Str::random(8));

            $transaction = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => WalletTransaction::TYPE_DEBIT,
                'amount' => $amountPaise,
                'reference_id' => $transferId,
                'transaction_type' => WalletTransaction::TXN_WITHDRAWAL,
                'status' => WalletTransaction::STATUS_PENDING,
                'description' => sprintf(
                    'Withdrawal to %s (%s)',
                    $bankAccount->bank_name,
                    $bankAccount->masked_account_number
                ),
                'metadata' => [
                    'bank_account_id' => $bankAccount->id,
                    'ifsc_code' => $bankAccount->ifsc_code,
                    'masked_account' => $bankAccount->masked_account_number,
                ],
            ]);

            $withdrawal = Withdrawal::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'bank_account_id' => $bankAccount->id,
                'wallet_transaction_id' => $transaction->id,
                'amount' => $amountPaise,
                'transfer_id' => $transferId,
                'idempotency_key' => filled($idempotencyKey) ? $idempotencyKey : null,
                'status' => Withdrawal::STATUS_PENDING,
            ]);

            return [
                'withdrawal' => $withdrawal,
                'is_duplicate' => false,
            ];
        });
    }

    /**
     * Mark a pending withdrawal as processing after Cashfree accepts it.
     */
    public function markWithdrawalProcessing(
        string $transferId,
        ?string $cfTransferId = null,
        ?string $statusDescription = null
    ): Withdrawal {
        return DB::transaction(function () use ($transferId, $cfTransferId, $statusDescription): Withdrawal {
            /** @var Withdrawal $withdrawal */
            $withdrawal = Withdrawal::where('transfer_id', $transferId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($withdrawal->isTerminal()) {
                return $withdrawal;
            }

            $withdrawal->status = Withdrawal::STATUS_PROCESSING;
            if (filled($cfTransferId)) {
                $withdrawal->cashfree_reference_id = $cfTransferId;
            }
            $withdrawal->save();

            if ($withdrawal->transaction) {
                $withdrawal->transaction->update([
                    'status' => WalletTransaction::STATUS_PROCESSING,
                    'gateway_reference_id' => $cfTransferId ?? $withdrawal->transaction->gateway_reference_id,
                    'metadata' => array_merge($withdrawal->transaction->metadata ?? [], array_filter([
                        'cf_transfer_id' => $cfTransferId,
                        'status_description' => $statusDescription,
                    ])),
                ]);
            }

            return $withdrawal->refresh();
        });
    }

    /**
     * Finalize debit once Cashfree confirms payout SUCCESS.
     * Idempotent via DB transaction and row locks.
     */
    public function finalizeWithdrawalSuccess(
        string $transferId,
        ?string $cfTransferId = null,
        ?string $utr = null,
        array $metadata = []
    ): Withdrawal {
        return DB::transaction(function () use ($transferId, $cfTransferId, $utr, $metadata): Withdrawal {
            /** @var Withdrawal $withdrawal */
            $withdrawal = Withdrawal::where('transfer_id', $transferId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($withdrawal->status === Withdrawal::STATUS_COMPLETED) {
                return $withdrawal;
            }

            if (in_array($withdrawal->status, [Withdrawal::STATUS_FAILED, Withdrawal::STATUS_REVERSED], true)) {
                Log::warning('Ignoring SUCCESS transition for already failed/reversed withdrawal', [
                    'transfer_id' => $transferId,
                    'current_status' => $withdrawal->status,
                ]);

                return $withdrawal;
            }

            /** @var Wallet $wallet */
            $wallet = Wallet::where('id', $withdrawal->wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            $wallet->reserved_balance = max(0, $wallet->reserved_balance - $withdrawal->amount);
            $wallet->save();

            $withdrawal->status = Withdrawal::STATUS_COMPLETED;
            if (filled($cfTransferId)) {
                $withdrawal->cashfree_reference_id = $cfTransferId;
            }
            if (filled($utr)) {
                $withdrawal->utr = $utr;
            }
            $withdrawal->failure_reason = null;
            $withdrawal->processed_at = now();
            $withdrawal->save();

            if ($withdrawal->transaction) {
                $withdrawal->transaction->update([
                    'status' => WalletTransaction::STATUS_COMPLETED,
                    'gateway_reference_id' => $cfTransferId ?? $withdrawal->cashfree_reference_id,
                    'metadata' => array_merge($withdrawal->transaction->metadata ?? [], $metadata, array_filter([
                        'cf_transfer_id' => $cfTransferId ?? $withdrawal->cashfree_reference_id,
                        'utr' => $utr,
                        'completed_at' => now()->toIso8601String(),
                    ])),
                ]);
            }

            return $withdrawal->refresh();
        });
    }

    /**
     * Release reserved balance back to available wallet balance if payout fails/is rejected/reversed.
     * Idempotent via DB transaction and row locks.
     */
    public function releaseWithdrawalFailure(
        string $transferId,
        string $reason,
        string $targetStatus = Withdrawal::STATUS_FAILED,
        ?string $cfTransferId = null,
        array $metadata = []
    ): Withdrawal {
        return DB::transaction(function () use ($transferId, $reason, $targetStatus, $cfTransferId, $metadata): Withdrawal {
            /** @var Withdrawal $withdrawal */
            $withdrawal = Withdrawal::where('transfer_id', $transferId)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($withdrawal->status, [Withdrawal::STATUS_FAILED, Withdrawal::STATUS_REVERSED], true)) {
                return $withdrawal;
            }

            /** @var Wallet $wallet */
            $wallet = Wallet::where('id', $withdrawal->wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($withdrawal->status === Withdrawal::STATUS_COMPLETED) {
                // Handle post-completion bank reversal
                if ($targetStatus !== Withdrawal::STATUS_REVERSED) {
                    return $withdrawal;
                }

                $wallet->balance += $withdrawal->amount;
                $wallet->save();

                $reversalRef = 'REV_' . $withdrawal->transfer_id;
                WalletTransaction::firstOrCreate(
                    ['reference_id' => $reversalRef],
                    [
                        'wallet_id' => $wallet->id,
                        'user_id' => $withdrawal->user_id,
                        'type' => WalletTransaction::TYPE_CREDIT,
                        'amount' => $withdrawal->amount,
                        'gateway_reference_id' => $cfTransferId ?? $withdrawal->cashfree_reference_id,
                        'transaction_type' => WalletTransaction::TXN_WITHDRAWAL_REVERSAL,
                        'status' => WalletTransaction::STATUS_COMPLETED,
                        'description' => 'Reversal for withdrawal ' . $withdrawal->transfer_id,
                        'metadata' => array_merge($metadata, ['reason' => $reason]),
                    ]
                );
            } else {
                // Release reserved balance back to available balance
                $wallet->reserved_balance = max(0, $wallet->reserved_balance - $withdrawal->amount);
                $wallet->balance += $withdrawal->amount;
                $wallet->save();

                if ($withdrawal->transaction) {
                    $withdrawal->transaction->update([
                        'status' => WalletTransaction::STATUS_FAILED,
                        'gateway_reference_id' => $cfTransferId ?? $withdrawal->cashfree_reference_id,
                        'metadata' => array_merge($withdrawal->transaction->metadata ?? [], $metadata, [
                            'failure_reason' => $reason,
                            'failed_at' => now()->toIso8601String(),
                        ]),
                    ]);
                }
            }

            $withdrawal->status = $targetStatus;
            if (filled($cfTransferId)) {
                $withdrawal->cashfree_reference_id = $cfTransferId;
            }
            $withdrawal->failure_reason = $reason;
            $withdrawal->processed_at = now();
            $withdrawal->save();

            return $withdrawal->refresh();
        });
    }

    /**
     * Apply a Cashfree Payouts V2 status response/webhook payload to a Withdrawal.
     */
    public function applyPayoutStatusUpdate(Withdrawal $withdrawal, array $payoutData): Withdrawal
    {
        $status = strtoupper((string) ($payoutData['status'] ?? 'PENDING'));
        $cfTransferId = $payoutData['cf_transfer_id'] ?? null;
        $utr = $payoutData['utr'] ?? ($payoutData['transfer_utr'] ?? null);
        $description = $payoutData['status_description'] ?? ($payoutData['reason'] ?? null);

        return match ($status) {
            'SUCCESS' => $this->finalizeWithdrawalSuccess(
                $withdrawal->transfer_id,
                $cfTransferId ? (string) $cfTransferId : null,
                $utr ? (string) $utr : null,
                ['cashfree_status' => $status]
            ),
            'FAILED', 'REJECTED', 'ERROR' => $this->releaseWithdrawalFailure(
                $withdrawal->transfer_id,
                $description ?: ('Payout ' . strtolower($status) . ' by Cashfree.'),
                Withdrawal::STATUS_FAILED,
                $cfTransferId ? (string) $cfTransferId : null,
                ['cashfree_status' => $status]
            ),
            'REVERSED' => $this->releaseWithdrawalFailure(
                $withdrawal->transfer_id,
                $description ?: 'Payout was reversed by the beneficiary bank.',
                Withdrawal::STATUS_REVERSED,
                $cfTransferId ? (string) $cfTransferId : null,
                ['cashfree_status' => $status]
            ),
            default => $this->markWithdrawalProcessing(
                $withdrawal->transfer_id,
                $cfTransferId ? (string) $cfTransferId : null,
                $description
            ),
        };
    }
}
