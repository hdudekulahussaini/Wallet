<?php

namespace App\Http\Middleware;

use App\Services\CashfreePaymentService;
use App\Services\CashfreePayoutService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyCashfreeWebhookSignature
{
    public function __construct(
        private readonly CashfreePaymentService $paymentService,
        private readonly CashfreePayoutService $payoutService
    ) {}

    /**
     * Verify incoming Cashfree webhook HMAC-SHA256 signature on the raw request body.
     */
    public function handle(Request $request, Closure $next, string $channel = 'payment'): Response
    {
        $rawBody = $request->getContent();
        $timestamp = $request->header('x-webhook-timestamp');
        $signature = $request->header('x-webhook-signature');

        $isValid = match ($channel) {
            'payout' => $this->payoutService->verifyWebhookSignature($rawBody, $timestamp, $signature),
            default => $this->paymentService->verifyWebhookSignature($rawBody, $timestamp, $signature),
        };

        if (! $isValid) {
            Log::warning('Rejected Cashfree webhook due to invalid signature', [
                'channel' => $channel,
                'ip' => $request->ip(),
                'has_timestamp' => filled($timestamp),
                'has_signature' => filled($signature),
            ]);

            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        return $next($request);
    }
}
