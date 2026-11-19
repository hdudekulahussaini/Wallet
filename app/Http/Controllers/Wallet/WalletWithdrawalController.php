<?php

namespace App\Http\Controllers\Wallet;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StoreWithdrawalRequest;
use App\Models\BankAccount;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\CashfreePayoutService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class WalletWithdrawalController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly CashfreePayoutService $payoutService
    ) {
        $this->middleware('auth')->except('webhook');
    }

    /**
     * Show withdrawal form and withdrawal history.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $wallet = $this->walletService->getOrCreateWallet($user);

        $verifiedAccounts = BankAccount::where('user_id', $user->id)
            ->where('is_verified', true)
            ->latest('id')
            ->get();

        $unverifiedCount = BankAccount::where('user_id', $user->id)
            ->where('is_verified', false)
            ->count();

        $withdrawals = Withdrawal::with('bankAccount')
            ->where('user_id', $user->id)
            ->latest('id')
            ->paginate(10);

        return view('wallet.withdraw', [
            'wallet' => $wallet,
            'verifiedAccounts' => $verifiedAccounts,
            'unverifiedCount' => $unverifiedCount,
            'withdrawals' => $withdrawals,
            'payoutConfigured' => $this->payoutService->isPayoutConfigured(),
            'idempotencyKey' => (string) Str::uuid(),
            'minWithdrawalRupees' => Wallet::paiseToRupees((int) config('cashfree.limits.min_withdrawal_paise', 100)),
            'maxWithdrawalRupees' => Wallet::paiseToRupees((int) config('cashfree.limits.max_withdrawal_paise', 10000000)),
        ]);
    }

    /**
     * Reserve wallet balance atomically and initiate Cashfree Payouts V2 transfer.
     */
    public function store(StoreWithdrawalRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $bankAccount = BankAccount::where('id', $validated['bank_account_id'])
            ->where('user_id', $user->id)
            ->first();

        if (! $bankAccount) {
            return redirect()->route('wallet.withdraw')
                ->with('error', 'Selected bank account was not found.');
        }

        if (! $bankAccount->is_verified) {
            return redirect()->route('wallet.withdraw')
                ->with('error', 'Only verified bank accounts can be used for withdrawals.');
        }

        $amountPaise = Wallet::rupeesToPaise((string) $validated['amount']);

        try {
            $reservation = $this->walletService->reserveWithdrawal(
                $user,
                $bankAccount,
                $amountPaise,
                $validated['idempotency_key']
            );
        } catch (Throwable $e) {
            return redirect()->route('wallet.withdraw')
                ->withInput($request->except('idempotency_key'))
                ->with('error', $e->getMessage());
        }

        /** @var Withdrawal $withdrawal */
        $withdrawal = $reservation['withdrawal'];

        if ($reservation['is_duplicate']) {
            return redirect()->route('wallet.withdraw')
                ->with('info', 'Duplicate withdrawal request detected and prevented. Tracking existing transfer: ' . $withdrawal->transfer_id);
        }

        try {
            $transferResult = $this->payoutService->createTransfer(
                $withdrawal->transfer_id,
                $withdrawal->amount,
                $bankAccount,
                $user
            );

            $updated = $this->walletService->applyPayoutStatusUpdate($withdrawal, $transferResult);

            if ($updated->status === Withdrawal::STATUS_COMPLETED) {
                return redirect()->route('wallet.withdraw')
                    ->with('success', sprintf(
                        'Withdrawal of %s completed! Transfer ID: %s%s',
                        $updated->formatted_amount,
                        $updated->transfer_id,
                        $updated->utr ? ' (UTR: ' . $updated->utr . ')' : ''
                    ));
            }

            if ($updated->status === Withdrawal::STATUS_FAILED) {
                return redirect()->route('wallet.withdraw')
                    ->with('error', sprintf(
                        'Withdrawal failed and %s was released back to your wallet. Reason: %s',
                        $updated->formatted_amount,
                        $updated->failure_reason ?? 'Payout rejected.'
                    ));
            }

            return redirect()->route('wallet.withdraw')
                ->with('info', sprintf(
                    'Withdrawal of %s is now processing (Transfer ID: %s). Your funds are reserved until bank confirmation.',
                    $updated->formatted_amount,
                    $updated->transfer_id
                ));
        } catch (Throwable $e) {
            $this->walletService->releaseWithdrawalFailure(
                $withdrawal->transfer_id,
                $e->getMessage(),
                Withdrawal::STATUS_FAILED
            );

            Log::error('Cashfree payout initiation failed; reserved balance released', [
                'transfer_id' => $withdrawal->transfer_id,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('wallet.withdraw')
                ->with('error', 'Withdrawal could not be initiated and your reserved balance has been released: ' . $e->getMessage());
        }
    }

    /**
     * Poll Cashfree Payouts V2 Get Transfer Status API to update a pending/processing withdrawal.
     */
    public function syncStatus(Withdrawal $withdrawal): RedirectResponse
    {
        $this->authorize('sync', $withdrawal);

        if ($withdrawal->isTerminal()) {
            return redirect()->route('wallet.withdraw')
                ->with('info', sprintf('Transfer %s is already in final state (%s).', $withdrawal->transfer_id, strtoupper($withdrawal->status)));
        }

        try {
            $statusData = $this->payoutService->getTransferStatus($withdrawal->transfer_id);
            $updated = $this->walletService->applyPayoutStatusUpdate($withdrawal, $statusData);

            $flashType = match ($updated->status) {
                Withdrawal::STATUS_COMPLETED => 'success',
                Withdrawal::STATUS_FAILED, Withdrawal::STATUS_REVERSED => 'error',
                default => 'info',
            };

            $message = match ($updated->status) {
                Withdrawal::STATUS_COMPLETED => sprintf('Transfer %s succeeded!%s', $updated->transfer_id, $updated->utr ? ' UTR: ' . $updated->utr : ''),
                Withdrawal::STATUS_FAILED, Withdrawal::STATUS_REVERSED => sprintf('Transfer %s %s. Reserved amount (%s) has been returned to your wallet.', $updated->transfer_id, $updated->status, $updated->formatted_amount),
                default => sprintf('Transfer %s is still %s at Cashfree.', $updated->transfer_id, $updated->status),
            };

            return redirect()->route('wallet.withdraw')->with($flashType, $message);
        } catch (Throwable $e) {
            Log::error('Cashfree payout status sync failed', [
                'transfer_id' => $withdrawal->transfer_id,
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('wallet.withdraw')
                ->with('error', 'Unable to sync transfer status right now: ' . $e->getMessage());
        }
    }

    /**
     * Receive and process Cashfree Payouts V2 webhooks.
     * Signature is verified by the VerifyCashfreeWebhookSignature middleware.
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid JSON payload.'], 400);
        }

        $eventType = strtoupper((string) ($payload['type'] ?? $payload['event'] ?? ''));
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $transferId = $data['transfer_id'] ?? ($data['transferId'] ?? null);

        if (! filled($transferId)) {
            return response()->json(['status' => 'ignored']);
        }

        $withdrawal = Withdrawal::where('transfer_id', $transferId)->first();

        if (! $withdrawal) {
            Log::warning('Cashfree Payout webhook received for unknown transfer_id', [
                'transfer_id' => $transferId,
                'event' => $eventType,
            ]);

            return response()->json(['status' => 'transfer_not_found'], 200);
        }

        try {
            $statusData = $this->payoutService->getTransferStatus($transferId);
            $updated = $this->walletService->applyPayoutStatusUpdate($withdrawal, $statusData);

            Log::info('Processed Cashfree Payout webhook', [
                'transfer_id' => $transferId,
                'event' => $eventType,
                'status' => $updated->status,
            ]);

            return response()->json([
                'status' => 'ok',
                'withdrawal_status' => $updated->status,
            ]);
        } catch (Throwable $e) {
            Log::error('Cashfree Payout webhook processing failed', [
                'transfer_id' => $transferId,
                'event' => $eventType,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Webhook processing error.'], 500);
        }
    }
}
