<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CashfreePayoutService
{
    public function isPayoutConfigured(): bool
    {
        return filled(config('cashfree.payouts.client_id'))
            && filled(config('cashfree.payouts.client_secret'));
    }

    public function isVerificationConfigured(): bool
    {
        return filled(config('cashfree.verification.client_id'))
            && filled(config('cashfree.verification.client_secret'));
    }

    /**
     * Verify bank account using Cashfree Bank Account Verification Sync V2 API
     * (POST /verification/bank-account/sync).
     *
     * @return array{verified: bool, account_status: string, name_at_bank: ?string, bank_name: ?string, branch: ?string, reference_id: ?string, message: string, raw: array}
     */
    public function verifyBankAccount(
        string $accountNumber,
        string $ifscCode,
        ?string $accountHolderName = null,
        ?string $phone = null
    ): array {
        if (! $this->isVerificationConfigured()) {
            throw new RuntimeException('Cashfree Verification API credentials are not configured. Set CASHFREE_VERIFICATION_CLIENT_ID and CASHFREE_VERIFICATION_CLIENT_SECRET in .env.');
        }

        $payload = [
            'bank_account' => preg_replace('/\s+/', '', $accountNumber),
            'ifsc' => strtoupper(trim($ifscCode)),
        ];

        if (filled($accountHolderName)) {
            $payload['name'] = trim($accountHolderName);
        }

        if (filled($phone)) {
            $cleanPhone = preg_replace('/\D+/', '', $phone);
            if (strlen($cleanPhone) >= 10) {
                $payload['phone'] = substr($cleanPhone, -10);
            }
        }

        try {
            $response = $this->verificationClient()->post('/bank-account/sync', $payload);
        } catch (ConnectionException $e) {
            Log::error('Cashfree Bank Account Verification connection error', [
                'ifsc' => $payload['ifsc'],
                'message' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to connect to Cashfree Bank Verification service. Please try again.');
        }

        $data = $response->json() ?? [];

        if (! $response->successful()) {
            $errorMessage = (string) ($data['message'] ?? 'Verification failed');
            Log::warning('Cashfree Bank Account Verification failed', [
                'status' => $response->status(),
                'ifsc' => $payload['ifsc'],
                'error_code' => $data['code'] ?? null,
                'error_message' => $errorMessage,
            ]);

            $suiteUnavailable = str_contains(strtolower($errorMessage), 'not enabled')
                || str_contains(strtolower($errorMessage), 'not subscribed')
                || str_contains(strtolower($errorMessage), 'verification suite');

            return [
                'verified' => false,
                'suite_unavailable' => $suiteUnavailable,
                'account_status' => (string) ($data['account_status'] ?? 'INVALID'),
                'name_at_bank' => null,
                'bank_name' => null,
                'branch' => null,
                'reference_id' => isset($data['reference_id']) ? (string) $data['reference_id'] : null,
                'message' => $errorMessage,
                'raw' => $data,
            ];
        }

        $accountStatus = strtoupper((string) ($data['account_status'] ?? ''));
        $isValid = $accountStatus === 'VALID';

        return [
            'verified' => $isValid,
            'suite_unavailable' => false,
            'account_status' => $accountStatus ?: 'UNKNOWN',
            'name_at_bank' => $data['name_at_bank'] ?? null,
            'bank_name' => $data['bank_name'] ?? ($data['ifsc_details']['bank'] ?? null),
            'branch' => $data['branch'] ?? ($data['ifsc_details']['branch'] ?? null),
            'reference_id' => isset($data['reference_id']) ? (string) $data['reference_id'] : null,
            'message' => $isValid
                ? 'Bank account verified successfully.'
                : (string) ($data['account_status_code'] ?? $data['message'] ?? 'Bank account could not be verified.'),
            'raw' => $data,
        ];
    }

    /**
     * Register or fetch a Beneficiary in Cashfree Payouts V2 (POST /payout/beneficiary).
     */
    public function ensureBeneficiary(BankAccount $bankAccount, User $user): string
    {
        if (! $this->isPayoutConfigured()) {
            throw new RuntimeException('Cashfree Payouts API credentials are not configured. Set CASHFREE_PAYOUT_CLIENT_ID and CASHFREE_PAYOUT_CLIENT_SECRET in .env.');
        }

        if (filled($bankAccount->cashfree_beneficiary_id)) {
            return $bankAccount->cashfree_beneficiary_id;
        }

        $beneficiaryId = sprintf('BENE_%d_%d', $user->id, $bankAccount->id);

        $phone = preg_replace('/\D+/', '', (string) ($user->phone ?? ''));
        if (strlen($phone) < 10) {
            $phone = '9999999999';
        } elseif (strlen($phone) > 10) {
            $phone = substr($phone, -10);
        }

        $payload = [
            'beneficiary_id' => $beneficiaryId,
            'beneficiary_name' => $bankAccount->account_holder_name,
            'beneficiary_instrument_details' => [
                'bank_account_number' => $bankAccount->account_number,
                'bank_ifsc' => strtoupper($bankAccount->ifsc_code),
            ],
            'beneficiary_contact_details' => [
                'beneficiary_email' => $user->email,
                'beneficiary_phone' => $phone,
            ],
        ];

        try {
            $response = $this->payoutClient()->post('/beneficiary', $payload);
        } catch (ConnectionException $e) {
            Log::error('Cashfree Payouts ensureBeneficiary connection error', [
                'bank_account_id' => $bankAccount->id,
                'message' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to reach Cashfree Payouts service while registering beneficiary.');
        }

        $data = $response->json() ?? [];

        // 409 Conflict indicates beneficiary already exists in Cashfree Payouts
        if ($response->status() === 409) {
            $bankAccount->forceFill(['cashfree_beneficiary_id' => $beneficiaryId])->save();

            return $beneficiaryId;
        }

        if (! $response->successful()) {
            Log::error('Cashfree Payouts createBeneficiary failed', [
                'bank_account_id' => $bankAccount->id,
                'status' => $response->status(),
                'error_code' => $data['code'] ?? null,
                'error_message' => $data['message'] ?? 'Failed to create beneficiary',
            ]);
            throw new RuntimeException((string) ($data['message'] ?? 'Failed to register bank account as a Cashfree payout beneficiary.'));
        }

        $resolvedId = (string) ($data['beneficiary_id'] ?? $beneficiaryId);
        $bankAccount->forceFill(['cashfree_beneficiary_id' => $resolvedId])->save();

        return $resolvedId;
    }

    /**
     * Register and verify a bank account via Cashfree Payouts V2 Beneficiary API
     * (POST /payout/beneficiary & GET /payout/beneficiary) when Verification Suite
     * is not enabled on the merchant account.
     *
     * @return array{verified: bool, beneficiary_id: ?string, beneficiary_status: string, message: string, raw: array}
     */
    public function verifyViaPayoutBeneficiary(BankAccount $bankAccount, User $user): array
    {
        if (! $this->isPayoutConfigured()) {
            throw new RuntimeException('Cashfree Payouts API credentials are not configured.');
        }

        $beneficiaryId = $bankAccount->cashfree_beneficiary_id ?: sprintf('BENE_%d_%d', $user->id, $bankAccount->id);

        $phone = preg_replace('/\D+/', '', (string) ($user->phone ?? ''));
        if (strlen($phone) < 10) {
            $phone = '9999999999';
        } elseif (strlen($phone) > 10) {
            $phone = substr($phone, -10);
        }

        $payload = [
            'beneficiary_id' => $beneficiaryId,
            'beneficiary_name' => $bankAccount->account_holder_name,
            'beneficiary_instrument_details' => [
                'bank_account_number' => $bankAccount->account_number,
                'bank_ifsc' => strtoupper($bankAccount->ifsc_code),
            ],
            'beneficiary_contact_details' => [
                'beneficiary_email' => $user->email,
                'beneficiary_phone' => $phone,
            ],
        ];

        try {
            $response = $this->payoutClient()->post('/beneficiary', $payload);

            // If beneficiary already exists (409), fetch its verified status from Cashfree Payouts
            if ($response->status() === 409) {
                $response = $this->payoutClient()->get('/beneficiary', [
                    'beneficiary_id' => $beneficiaryId,
                ]);
            }
        } catch (ConnectionException $e) {
            Log::error('Cashfree Payouts verifyViaPayoutBeneficiary connection error', [
                'bank_account_id' => $bankAccount->id,
                'message' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to reach Cashfree Payouts service for beneficiary verification.');
        }

        $data = $response->json() ?? [];

        if (! $response->successful()) {
            return [
                'verified' => false,
                'beneficiary_id' => null,
                'beneficiary_status' => 'FAILED',
                'message' => (string) ($data['message'] ?? 'Cashfree Payouts rejected the bank account details.'),
                'raw' => $data,
            ];
        }

        $status = strtoupper((string) ($data['beneficiary_status'] ?? 'VERIFIED'));
        $isVerified = in_array($status, ['VERIFIED', 'ACTIVE'], true);
        $resolvedId = (string) ($data['beneficiary_id'] ?? $beneficiaryId);

        return [
            'verified' => $isVerified,
            'beneficiary_id' => $resolvedId,
            'beneficiary_status' => $status,
            'message' => $isVerified
                ? 'Bank account verified and registered with Cashfree Payouts.'
                : 'Beneficiary status is ' . $status . '.',
            'raw' => $data,
        ];
    }

    /**
     * Initiate a Cashfree Payouts V2 Standard Transfer (POST /payout/transfers).
     *
     * @return array{status: string, cf_transfer_id: ?string, utr: ?string, status_description: ?string, is_ambiguous: bool, raw: array}
     */
    public function createTransfer(
        string $transferId,
        int $amountPaise,
        BankAccount $bankAccount,
        User $user,
        ?string $remarks = null
    ): array {
        if (! $this->isPayoutConfigured()) {
            throw new RuntimeException('Cashfree Payouts API credentials are not configured. Set CASHFREE_PAYOUT_CLIENT_ID and CASHFREE_PAYOUT_CLIENT_SECRET in .env.');
        }

        $beneficiaryId = $this->ensureBeneficiary($bankAccount, $user);

        $rawRemarks = $remarks ?? ('Wallet withdrawal ' . str_replace('_', ' ', $transferId));
        $sanitizedRemarks = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Za-z0-9 ]+/', ' ', $rawRemarks)));
        $sanitizedRemarks = substr($sanitizedRemarks ?: 'Wallet withdrawal', 0, 50);

        $payload = [
            'transfer_id' => $transferId,
            'transfer_amount' => (float) Wallet::paiseToRupees($amountPaise),
            'transfer_mode' => (string) config('cashfree.payouts.transfer_mode', 'banktransfer'),
            'transfer_remarks' => $sanitizedRemarks,
            'beneficiary_details' => [
                'beneficiary_id' => $beneficiaryId,
            ],
        ];

        try {
            $response = $this->payoutClient()->post('/transfers', $payload);
        } catch (ConnectionException $e) {
            // Network timeout / disconnect is ambiguous: the transfer may have been accepted by Cashfree.
            Log::error('Cashfree Payouts createTransfer connection error (ambiguous state)', [
                'transfer_id' => $transferId,
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => 'PENDING',
                'cf_transfer_id' => null,
                'utr' => null,
                'status_description' => 'Connection timed out while communicating with Cashfree. Status will be confirmed via sync/webhook.',
                'is_ambiguous' => true,
                'raw' => [],
            ];
        }

        $data = $response->json() ?? [];

        if (! $response->successful()) {
            Log::error('Cashfree Payouts createTransfer failed', [
                'transfer_id' => $transferId,
                'status' => $response->status(),
                'error_code' => $data['code'] ?? null,
                'error_message' => $data['message'] ?? 'Transfer failed',
            ]);

            // 5xx responses may be ambiguous; 4xx responses are deterministic rejections
            $isServerError = $response->status() >= 500;

            return [
                'status' => $isServerError ? 'PENDING' : 'FAILED',
                'cf_transfer_id' => isset($data['cf_transfer_id']) ? (string) $data['cf_transfer_id'] : null,
                'utr' => null,
                'status_description' => (string) ($data['message'] ?? 'Payout transfer request was rejected by Cashfree.'),
                'is_ambiguous' => $isServerError,
                'raw' => $data,
            ];
        }

        return [
            'status' => strtoupper((string) ($data['status'] ?? 'RECEIVED')),
            'cf_transfer_id' => isset($data['cf_transfer_id']) ? (string) $data['cf_transfer_id'] : null,
            'utr' => $data['transfer_utr'] ?? ($data['utr'] ?? null),
            'status_description' => $data['status_description'] ?? null,
            'is_ambiguous' => false,
            'raw' => $data,
        ];
    }

    /**
     * Fetch transfer status from Cashfree Payouts V2 (GET /payout/transfers?transfer_id=...).
     *
     * @return array{status: string, cf_transfer_id: ?string, utr: ?string, status_description: ?string, raw: array}
     */
    public function getTransferStatus(string $transferId): array
    {
        if (! $this->isPayoutConfigured()) {
            throw new RuntimeException('Cashfree Payouts API credentials are not configured.');
        }

        try {
            $response = $this->payoutClient()->get('/transfers', [
                'transfer_id' => $transferId,
            ]);
        } catch (ConnectionException $e) {
            Log::error('Cashfree Payouts getTransferStatus connection error', [
                'transfer_id' => $transferId,
                'message' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to fetch payout status from Cashfree right now.');
        }

        $data = $response->json() ?? [];

        if (! $response->successful()) {
            $errorMessage = (string) ($data['message'] ?? 'Unable to fetch payout status from Cashfree.');
            $errorCode = strtolower((string) ($data['code'] ?? ''));

            // If Cashfree explicitly returns 404 (transfer_id does not exist — e.g. after an upstream 500 during creation),
            // treat as FAILED so the reserved wallet balance is safely released back to the user.
            if (
                $response->status() === 404
                && (
                    str_contains($errorCode, 'not_found')
                    || str_contains(strtolower($errorMessage), 'does not exist')
                    || str_contains(strtolower($errorMessage), 'not found')
                )
            ) {
                return [
                    'status' => 'FAILED',
                    'cf_transfer_id' => null,
                    'utr' => null,
                    'status_description' => 'Transfer was not created at Cashfree; reserved funds released.',
                    'raw' => $data,
                ];
            }

            Log::warning('Cashfree Payouts getTransferStatus failed', [
                'transfer_id' => $transferId,
                'status' => $response->status(),
                'error_code' => $data['code'] ?? null,
                'error_message' => $errorMessage,
            ]);
            throw new RuntimeException($errorMessage);
        }

        return [
            'status' => strtoupper((string) ($data['status'] ?? 'PENDING')),
            'cf_transfer_id' => isset($data['cf_transfer_id']) ? (string) $data['cf_transfer_id'] : null,
            'utr' => $data['transfer_utr'] ?? ($data['utr'] ?? null),
            'status_description' => $data['status_description'] ?? null,
            'raw' => $data,
        ];
    }

    /**
     * Verify Cashfree Payouts V2 webhook signature.
     * Signature = base64_encode(HMAC-SHA256(x-webhook-timestamp + rawBody, payoutClientSecret))
     */
    public function verifyWebhookSignature(string $rawBody, ?string $timestamp, ?string $signature): bool
    {
        $secret = (string) config('cashfree.payouts.webhook_secret', '');

        if ($secret === '' || empty($timestamp) || empty($signature)) {
            return false;
        }

        $signedPayload = $timestamp . $rawBody;
        $computed = base64_encode(hash_hmac('sha256', $signedPayload, $secret, true));

        return hash_equals($computed, $signature);
    }

    protected function payoutClient(): PendingRequest
    {
        return Http::baseUrl((string) config('cashfree.payouts.base_url'))
            ->timeout((int) config('cashfree.payouts.timeout', 30))
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'x-api-version' => (string) config('cashfree.payouts.api_version', '2024-01-01'),
                'x-client-id' => (string) config('cashfree.payouts.client_id'),
                'x-client-secret' => (string) config('cashfree.payouts.client_secret'),
            ]);
    }

    protected function verificationClient(): PendingRequest
    {
        return Http::baseUrl((string) config('cashfree.verification.base_url'))
            ->timeout((int) config('cashfree.verification.timeout', 30))
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'x-client-id' => (string) config('cashfree.verification.client_id'),
                'x-client-secret' => (string) config('cashfree.verification.client_secret'),
            ]);
    }
}
