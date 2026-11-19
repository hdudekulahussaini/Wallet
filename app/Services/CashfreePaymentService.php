<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CashfreePaymentService
{
    public function isConfigured(): bool
    {
        return filled(config('cashfree.payment_gateway.client_id'))
            && filled(config('cashfree.payment_gateway.client_secret'));
    }

    public function getCheckoutMode(): string
    {
        return (string) config('cashfree.payment_gateway.checkout_mode', 'sandbox');
    }

    public function getJsSdkUrl(): string
    {
        return (string) config('cashfree.payment_gateway.js_sdk_url', 'https://sdk.cashfree.com/js/v3/cashfree.js');
    }

    /**
     * Create a Cashfree Payment Gateway order (API v2025-01-01).
     *
     * @return array{cf_order_id: ?string, order_id: string, payment_session_id: string, order_status: string, raw: array}
     */
    public function createOrder(
        string $orderId,
        int $amountPaise,
        User $user,
        string $returnUrl,
        ?string $notifyUrl = null
    ): array {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Cashfree Payment Gateway credentials are not configured. Set CASHFREE_CLIENT_ID and CASHFREE_CLIENT_SECRET in .env.');
        }

        $phone = preg_replace('/\D+/', '', (string) ($user->phone ?? ''));
        if (strlen($phone) < 10) {
            $phone = '9999999999';
        } elseif (strlen($phone) > 10) {
            $phone = substr($phone, -10);
        }

        $orderMeta = [
            'return_url' => $returnUrl,
        ];

        // Cashfree requires HTTPS for notify_url; omit for local http:// URLs so sandbox order creation succeeds.
        if ($notifyUrl && str_starts_with($notifyUrl, 'https://')) {
            $orderMeta['notify_url'] = $notifyUrl;
        }

        $payload = [
            'order_id' => $orderId,
            'order_amount' => (float) Wallet::paiseToRupees($amountPaise),
            'order_currency' => 'INR',
            'customer_details' => [
                'customer_id' => 'CUST_' . $user->id,
                'customer_name' => $user->name,
                'customer_email' => $user->email,
                'customer_phone' => $phone,
            ],
            'order_meta' => $orderMeta,
            'order_note' => 'Wallet deposit for user #' . $user->id,
        ];

        try {
            $response = $this->httpClient()->post('/orders', $payload);
        } catch (ConnectionException $e) {
            Log::error('Cashfree PG createOrder connection error', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to connect to Cashfree Payment Gateway. Please try again.');
        }

        if (! $response->successful()) {
            $errorData = $response->json() ?? [];
            Log::error('Cashfree PG createOrder failed', [
                'order_id' => $orderId,
                'status' => $response->status(),
                'error_code' => $errorData['code'] ?? null,
                'error_message' => $errorData['message'] ?? $response->body(),
            ]);

            $message = $errorData['message'] ?? 'Failed to initialize payment order with Cashfree.';
            if ($response->status() === 401) {
                $message .= ' Verify that CASHFREE_CLIENT_ID and CASHFREE_CLIENT_SECRET are Cashfree Payment Gateway keys (not Payouts keys) matching CASHFREE_ENV=' . config('cashfree.env') . '.';
            }

            throw new RuntimeException($message);
        }

        $data = $response->json();

        if (empty($data['payment_session_id'])) {
            Log::error('Cashfree PG createOrder missing payment_session_id', [
                'order_id' => $orderId,
            ]);
            throw new RuntimeException('Cashfree did not return a payment session ID.');
        }

        return [
            'cf_order_id' => isset($data['cf_order_id']) ? (string) $data['cf_order_id'] : null,
            'order_id' => (string) ($data['order_id'] ?? $orderId),
            'payment_session_id' => (string) $data['payment_session_id'],
            'order_status' => (string) ($data['order_status'] ?? 'ACTIVE'),
            'raw' => $data,
        ];
    }

    /**
     * Fetch order details from Cashfree server-side API (GET /pg/orders/{order_id}).
     */
    public function getOrder(string $orderId): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Cashfree Payment Gateway credentials are not configured.');
        }

        try {
            $response = $this->httpClient()->get('/orders/' . urlencode($orderId));
        } catch (ConnectionException $e) {
            Log::error('Cashfree PG getOrder connection error', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to verify order status with Cashfree right now.');
        }

        if (! $response->successful()) {
            $errorData = $response->json() ?? [];
            Log::error('Cashfree PG getOrder failed', [
                'order_id' => $orderId,
                'status' => $response->status(),
                'error_message' => $errorData['message'] ?? 'Unknown error',
            ]);
            throw new RuntimeException($errorData['message'] ?? 'Unable to fetch order details from Cashfree.');
        }

        return $response->json() ?? [];
    }

    /**
     * Fetch all payment attempts for an order (GET /pg/orders/{order_id}/payments).
     */
    public function getOrderPayments(string $orderId): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Cashfree Payment Gateway credentials are not configured.');
        }

        try {
            $response = $this->httpClient()->get('/orders/' . urlencode($orderId) . '/payments');
        } catch (ConnectionException $e) {
            Log::warning('Cashfree PG getOrderPayments connection error', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('Cashfree PG getOrderPayments failed', [
                'order_id' => $orderId,
                'status' => $response->status(),
            ]);

            return [];
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * Verify Cashfree Payment Gateway webhook signature (v2025-01-01).
     * Signature = base64_encode(HMAC-SHA256(x-webhook-timestamp + rawBody, secretKey))
     */
    public function verifyWebhookSignature(string $rawBody, ?string $timestamp, ?string $signature): bool
    {
        $secret = (string) config('cashfree.payment_gateway.webhook_secret', '');

        if ($secret === '' || empty($timestamp) || empty($signature)) {
            return false;
        }

        $signedPayload = $timestamp . $rawBody;
        $computed = base64_encode(hash_hmac('sha256', $signedPayload, $secret, true));

        return hash_equals($computed, $signature);
    }

    protected function httpClient(): PendingRequest
    {
        return Http::baseUrl((string) config('cashfree.payment_gateway.base_url'))
            ->timeout((int) config('cashfree.payment_gateway.timeout', 30))
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'x-api-version' => (string) config('cashfree.payment_gateway.api_version', '2025-01-01'),
                'x-client-id' => (string) config('cashfree.payment_gateway.client_id'),
                'x-client-secret' => (string) config('cashfree.payment_gateway.client_secret'),
            ]);
    }
}
