<?php

namespace App\Http\Controllers\Wallet;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\CreateDepositOrderRequest;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\CashfreePaymentService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class WalletPaymentController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly CashfreePaymentService $paymentService
    ) {
        $this->middleware('auth')->except('webhook');
    }

    /**
     * Show Add Money page with Cashfree Checkout integration.
     */
    public function showAddMoney(Request $request): View
    {
        $wallet = $this->walletService->getOrCreateWallet($request->user());

        $recentDeposits = WalletTransaction::where('wallet_id', $wallet->id)
            ->where('transaction_type', WalletTransaction::TXN_DEPOSIT)
            ->latest('id')
            ->limit(10)
            ->get();

        return view('wallet.add-money', [
            'wallet' => $wallet,
            'recentDeposits' => $recentDeposits,
            'pgConfigured' => $this->paymentService->isConfigured(),
            'checkoutMode' => $this->paymentService->getCheckoutMode(),
            'jsSdkUrl' => $this->paymentService->getJsSdkUrl(),
            'minDepositRupees' => Wallet::paiseToRupees((int) config('cashfree.limits.min_deposit_paise', 100)),
            'maxDepositRupees' => Wallet::paiseToRupees((int) config('cashfree.limits.max_deposit_paise', 10000000)),
        ]);
    }

    /**
     * Validate deposit amount, create pending transaction, create Cashfree order,
     * and return payment_session_id for the Cashfree JS SDK.
     */
    public function createOrder(CreateDepositOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        if (! empty($validated['phone']) && $user->phone !== $validated['phone']) {
            $user->forceFill(['phone' => $validated['phone']])->save();
        }

        $amountPaise = Wallet::rupeesToPaise((string) $validated['amount']);
        $transaction = $this->walletService->createPendingDeposit($user, $amountPaise);

        try {
            $returnUrl = route('wallet.add-money.return') . '?order_id=' . urlencode($transaction->reference_id);
            $notifyUrl = route('webhooks.cashfree.payment');

            $orderResponse = $this->paymentService->createOrder(
                $transaction->reference_id,
                $amountPaise,
                $user,
                $returnUrl,
                $notifyUrl
            );

            $this->walletService->attachPaymentSession(
                $transaction,
                $orderResponse['payment_session_id'],
                $orderResponse['cf_order_id'],
                [
                    'cashfree_order_status' => $orderResponse['order_status'],
                ]
            );

            return response()->json([
                'success' => true,
                'order_id' => $transaction->reference_id,
                'payment_session_id' => $orderResponse['payment_session_id'],
                'amount_paise' => $amountPaise,
                'formatted_amount' => Wallet::formatPaise($amountPaise),
                'checkout_mode' => $this->paymentService->getCheckoutMode(),
                'verify_url' => route('wallet.add-money.verify', ['orderId' => $transaction->reference_id]),
            ]);
        } catch (Throwable $e) {
            $this->walletService->markDepositFailed($transaction->reference_id, $e->getMessage());

            Log::error('Failed to initiate Cashfree deposit order', [
                'order_id' => $transaction->reference_id,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Handle Cashfree Checkout return_url redirect.
     * Never credits based on frontend query params: always confirms via Cashfree server-side API.
     */
    public function handleReturn(Request $request): RedirectResponse
    {
        $orderId = trim((string) $request->query('order_id', ''));

        if ($orderId === '') {
            return redirect()->route('wallet.add-money')
                ->with('error', 'Missing payment order reference.');
        }

        $transaction = WalletTransaction::where('reference_id', $orderId)
            ->where('user_id', $request->user()->id)
            ->where('transaction_type', WalletTransaction::TXN_DEPOSIT)
            ->first();

        if (! $transaction) {
            return redirect()->route('wallet.add-money')
                ->with('error', 'Payment order not found.');
        }

        try {
            $orderData = $this->paymentService->getOrder($orderId);
            $paymentsData = $this->paymentService->getOrderPayments($orderId);

            $updated = $this->walletService->confirmDeposit($orderId, $orderData, $paymentsData);

            if ($updated->status === WalletTransaction::STATUS_COMPLETED) {
                return redirect()->route('wallet.index')
                    ->with('success', sprintf(
                        '%s has been added to your wallet successfully (Order: %s).',
                        $updated->formatted_amount,
                        $updated->reference_id
                    ));
            }

            if ($updated->status === WalletTransaction::STATUS_FAILED) {
                $reason = $updated->metadata['failure_reason'] ?? 'Payment was not completed.';

                return redirect()->route('wallet.add-money')
                    ->with('error', 'Payment failed: ' . $reason);
            }

            return redirect()->route('wallet.add-money')
                ->with('info', 'Payment is still being processed by Cashfree. Your wallet will be credited automatically once confirmed.');
        } catch (Throwable $e) {
            Log::error('Error verifying Cashfree return order', [
                'order_id' => $orderId,
                'user_id' => $request->user()->id,
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('wallet.add-money')
                ->with('warning', 'We could not immediately verify the payment status. Please check your transaction history or click Verify Status shortly.');
        }
    }

    /**
     * Server-side verification endpoint callable by authenticated user for a specific deposit order.
     */
    public function verifyOrder(Request $request, string $orderId): JsonResponse|RedirectResponse
    {
        $transaction = WalletTransaction::where('reference_id', $orderId)
            ->where('user_id', $request->user()->id)
            ->where('transaction_type', WalletTransaction::TXN_DEPOSIT)
            ->firstOrFail();

        try {
            $orderData = $this->paymentService->getOrder($orderId);
            $paymentsData = $this->paymentService->getOrderPayments($orderId);

            $updated = $this->walletService->confirmDeposit($orderId, $orderData, $paymentsData);
            $wallet = $this->walletService->getOrCreateWallet($request->user())->refresh();

            $message = match ($updated->status) {
                WalletTransaction::STATUS_COMPLETED => sprintf('%s credited to your wallet.', $updated->formatted_amount),
                WalletTransaction::STATUS_FAILED => 'Payment failed: ' . ($updated->metadata['failure_reason'] ?? 'Transaction unsuccessful.'),
                default => 'Payment is still pending confirmation from Cashfree.',
            };

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => $updated->status === WalletTransaction::STATUS_COMPLETED,
                    'status' => $updated->status,
                    'message' => $message,
                    'order_id' => $updated->reference_id,
                    'balance_paise' => $wallet->balance,
                    'formatted_balance' => $wallet->formatted_balance,
                ]);
            }

            $flashType = $updated->status === WalletTransaction::STATUS_COMPLETED
                ? 'success'
                : ($updated->status === WalletTransaction::STATUS_FAILED ? 'error' : 'info');

            return redirect()->route('wallet.add-money')->with($flashType, $message);
        } catch (Throwable $e) {
            Log::error('Cashfree manual order verification error', [
                'order_id' => $orderId,
                'user_id' => $request->user()->id,
                'message' => $e->getMessage(),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'status' => $transaction->status,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return redirect()->route('wallet.add-money')->with('error', $e->getMessage());
        }
    }

    /**
     * Receive and process Cashfree Payment Gateway webhooks (v2025-01-01).
     * Signature is verified by the VerifyCashfreeWebhookSignature middleware.
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid JSON payload.'], 400);
        }

        $eventType = strtoupper((string) ($payload['type'] ?? ''));
        $orderId = $payload['data']['order']['order_id'] ?? null;

        if (! filled($orderId)) {
            Log::info('Cashfree PG webhook received without order_id', ['type' => $eventType]);

            return response()->json(['status' => 'ignored']);
        }

        $transaction = WalletTransaction::where('reference_id', $orderId)
            ->where('transaction_type', WalletTransaction::TXN_DEPOSIT)
            ->first();

        if (! $transaction) {
            Log::warning('Cashfree PG webhook received for unknown order_id', [
                'order_id' => $orderId,
                'type' => $eventType,
            ]);

            return response()->json(['status' => 'order_not_found'], 200);
        }

        try {
            $orderData = $this->paymentService->getOrder($orderId);
            $paymentsData = $this->paymentService->getOrderPayments($orderId);

            $updated = $this->walletService->confirmDeposit($orderId, $orderData, $paymentsData);

            Log::info('Processed Cashfree PG webhook', [
                'order_id' => $orderId,
                'event_type' => $eventType,
                'status' => $updated->status,
            ]);

            return response()->json([
                'status' => 'ok',
                'transaction_status' => $updated->status,
            ]);
        } catch (Throwable $e) {
            Log::error('Cashfree PG webhook processing failed', [
                'order_id' => $orderId,
                'event_type' => $eventType,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Webhook processing error.'], 500);
        }
    }
}
