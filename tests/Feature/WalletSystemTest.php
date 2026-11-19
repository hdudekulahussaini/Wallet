<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WalletSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashfree.env' => 'sandbox',
            'cashfree.payment_gateway.client_id' => 'TEST_PG_CLIENT_ID',
            'cashfree.payment_gateway.client_secret' => 'TEST_PG_CLIENT_SECRET',
            'cashfree.payment_gateway.webhook_secret' => 'TEST_PG_CLIENT_SECRET',
            'cashfree.payment_gateway.api_version' => '2025-01-01',
            'cashfree.payment_gateway.base_url' => 'https://sandbox.cashfree.com/pg',
            'cashfree.payouts.client_id' => 'TEST_PAYOUT_CLIENT_ID',
            'cashfree.payouts.client_secret' => 'TEST_PAYOUT_CLIENT_SECRET',
            'cashfree.payouts.webhook_secret' => 'TEST_PAYOUT_CLIENT_SECRET',
            'cashfree.payouts.api_version' => '2024-01-01',
            'cashfree.payouts.base_url' => 'https://sandbox.cashfree.com/payout',
            'cashfree.verification.client_id' => 'TEST_VERIF_CLIENT_ID',
            'cashfree.verification.client_secret' => 'TEST_VERIF_CLIENT_SECRET',
            'cashfree.verification.base_url' => 'https://sandbox.cashfree.com/verification',
        ]);
    }

    private function signWebhook(string $rawBody, string $timestamp, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $timestamp . $rawBody, $secret, true));
    }

    public function test_guest_cannot_access_protected_wallet_routes(): void
    {
        $this->get(route('wallet.index'))->assertRedirect(route('login'));
        $this->get(route('wallet.balance'))->assertRedirect(route('login'));
        $this->get(route('wallet.add-money'))->assertRedirect(route('login'));
        $this->get(route('wallet.withdraw'))->assertRedirect(route('login'));
        $this->get(route('wallet.bank-accounts'))->assertRedirect(route('login'));
        $this->get(route('wallet.transactions'))->assertRedirect(route('login'));
    }

    public function test_registration_creates_wallet_and_login_logout_flow_works(): void
    {
        $response = $this->post(route('register'), [
            'name' => 'Aarav Sharma',
            'email' => 'aarav@example.com',
            'phone' => '9876543210',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $response->assertRedirect('/wallet');
        $this->assertAuthenticated();

        $user = User::where('email', 'aarav@example.com')->firstOrFail();
        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'balance' => 0,
            'reserved_balance' => 0,
        ]);

        // Dashboard and JSON balance endpoint
        $this->get(route('wallet.index'))
            ->assertOk()
            ->assertSee('Available Wallet Balance')
            ->assertSee('₹0.00');

        $this->getJson(route('wallet.balance'))
            ->assertOk()
            ->assertJson([
                'balance_paise' => 0,
                'reserved_balance_paise' => 0,
                'formatted_balance' => '₹0.00',
            ]);

        // Logout & Login back in
        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();

        $this->post(route('login'), [
            'email' => 'aarav@example.com',
            'password' => 'SecurePass123!',
        ])->assertRedirect('/wallet');

        $this->assertAuthenticatedAs($user);
    }

    public function test_successful_deposit_via_cashfree_order_and_verified_webhook(): void
    {
        $user = User::factory()->create(['phone' => '9876543210']);

        Http::fake([
            'https://sandbox.cashfree.com/pg/orders' => Http::response([
                'cf_order_id' => '2149460581',
                'order_status' => 'ACTIVE',
                'payment_session_id' => 'session_sandbox_test_123456',
            ], 200),
            'https://sandbox.cashfree.com/pg/orders/*/payments' => Http::response([
                [
                    'cf_payment_id' => '987654321',
                    'payment_status' => 'SUCCESS',
                    'payment_group' => 'upi',
                    'bank_reference' => 'UTR123456789',
                ],
            ], 200),
            'https://sandbox.cashfree.com/pg/orders/*' => Http::response([
                'cf_order_id' => '2149460581',
                'order_amount' => 500.50,
                'order_currency' => 'INR',
                'order_status' => 'PAID',
            ], 200),
        ]);

        // Step 1-5: Create Cashfree order and receive payment_session_id
        $orderRes = $this->actingAs($user)->postJson(route('wallet.add-money.order'), [
            'amount' => '500.50',
            'phone' => '9876543210',
        ]);

        $orderRes->assertOk()
            ->assertJson([
                'success' => true,
                'payment_session_id' => 'session_sandbox_test_123456',
                'amount_paise' => 50050,
                'checkout_mode' => 'sandbox',
            ]);

        $orderId = $orderRes->json('order_id');

        // Wallet must STILL be 0 before server-side confirmation!
        $this->assertSame(0, $user->wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'reference_id' => $orderId,
            'status' => WalletTransaction::STATUS_PENDING,
            'amount' => 50050,
        ]);

        // Step 8-12: Signed Cashfree PG Webhook triggers server-side verification and credits wallet
        $webhookBody = json_encode([
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'data' => [
                'order' => [
                    'order_id' => $orderId,
                    'order_amount' => 500.50,
                ],
                'payment' => [
                    'cf_payment_id' => '987654321',
                    'payment_status' => 'SUCCESS',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $timestamp = (string) now()->getTimestampMs();
        $signature = $this->signWebhook($webhookBody, $timestamp, 'TEST_PG_CLIENT_SECRET');

        $webhookRes = $this->call(
            'POST',
            route('webhooks.cashfree.payment'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            $webhookBody
        );

        $webhookRes->assertOk()->assertJson([
            'status' => 'ok',
            'transaction_status' => 'completed',
        ]);

        // Wallet credited with exact integer paise (50050)
        $this->assertSame(50050, $user->wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'reference_id' => $orderId,
            'status' => WalletTransaction::STATUS_COMPLETED,
            'gateway_reference_id' => '987654321',
        ]);
    }

    public function test_duplicate_payment_webhook_credits_wallet_strictly_once_and_invalid_signature_is_rejected(): void
    {
        $user = User::factory()->create();
        $wallet = $user->wallet;

        $txn = WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'type' => WalletTransaction::TYPE_CREDIT,
            'amount' => 100000, // ₹1,000.00
            'reference_id' => 'ORD_DUP_TEST_001',
            'transaction_type' => WalletTransaction::TXN_DEPOSIT,
            'status' => WalletTransaction::STATUS_PENDING,
        ]);

        Http::fake([
            'https://sandbox.cashfree.com/pg/orders/ORD_DUP_TEST_001/payments' => Http::response([
                ['cf_payment_id' => '555001', 'payment_status' => 'SUCCESS'],
            ], 200),
            'https://sandbox.cashfree.com/pg/orders/ORD_DUP_TEST_001' => Http::response([
                'cf_order_id' => 'CF_1001',
                'order_amount' => 1000.00,
                'order_status' => 'PAID',
            ], 200),
        ]);

        $body = json_encode([
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'data' => ['order' => ['order_id' => $txn->reference_id]],
        ], JSON_THROW_ON_ERROR);

        $timestamp = (string) now()->getTimestampMs();

        // Invalid signature rejected with 401 and balance remains 0
        $this->call(
            'POST',
            route('webhooks.cashfree.payment'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
                'HTTP_X_WEBHOOK_SIGNATURE' => 'tampered-signature',
            ],
            $body
        )->assertStatus(401);

        $this->assertSame(0, $wallet->fresh()->balance);

        // First valid webhook credits ₹1,000.00
        $signature = $this->signWebhook($body, $timestamp, 'TEST_PG_CLIENT_SECRET');
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ];

        $this->call('POST', route('webhooks.cashfree.payment'), [], [], [], $headers, $body)->assertOk();
        $this->assertSame(100000, $wallet->fresh()->balance);

        // Duplicate second valid webhook MUST NOT credit again
        $this->call('POST', route('webhooks.cashfree.payment'), [], [], [], $headers, $body)->assertOk();
        $this->assertSame(100000, $wallet->fresh()->balance);
    }

    public function test_failed_deposit_does_not_credit_wallet(): void
    {
        $user = User::factory()->create();
        $wallet = $user->wallet;

        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'type' => WalletTransaction::TYPE_CREDIT,
            'amount' => 25000,
            'reference_id' => 'ORD_FAIL_TEST_001',
            'transaction_type' => WalletTransaction::TXN_DEPOSIT,
            'status' => WalletTransaction::STATUS_PENDING,
        ]);

        Http::fake([
            'https://sandbox.cashfree.com/pg/orders/ORD_FAIL_TEST_001/payments' => Http::response([
                [
                    'cf_payment_id' => '555002',
                    'payment_status' => 'FAILED',
                    'payment_message' => 'Insufficient funds at customer bank',
                ],
            ], 200),
            'https://sandbox.cashfree.com/pg/orders/ORD_FAIL_TEST_001' => Http::response([
                'cf_order_id' => 'CF_1002',
                'order_amount' => 250.00,
                'order_status' => 'EXPIRED',
            ], 200),
        ]);

        $response = $this->actingAs($user)
            ->get(route('wallet.add-money.return', ['order_id' => 'ORD_FAIL_TEST_001']));

        $response->assertRedirect(route('wallet.add-money'));
        $response->assertSessionHas('error');

        $this->assertSame(0, $wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'reference_id' => 'ORD_FAIL_TEST_001',
            'status' => WalletTransaction::STATUS_FAILED,
        ]);
    }

    public function test_bank_account_registration_encryption_ifsc_validation_and_cashfree_verification(): void
    {
        $user = User::factory()->create(['phone' => '9876543210']);
        $otherUser = User::factory()->create();

        // Invalid IFSC fails validation
        $this->actingAs($user)->post(route('wallet.bank-accounts.store'), [
            'account_holder_name' => 'Aarav Sharma',
            'account_number' => '123456789012',
            'account_number_confirmation' => '123456789012',
            'ifsc_code' => 'INVALID1234',
            'bank_name' => 'HDFC Bank',
        ])->assertSessionHasErrors('ifsc_code');

        Http::fake([
            'https://sandbox.cashfree.com/verification/bank-account/sync' => Http::response([
                'reference_id' => 98765,
                'name_at_bank' => 'AARAV SHARMA',
                'bank_name' => 'HDFC BANK',
                'branch' => 'MUMBAI MAIN',
                'account_status' => 'VALID',
                'account_status_code' => 'ACCOUNT_IS_VALID',
            ], 200),
            'https://sandbox.cashfree.com/payout/beneficiary' => Http::response([
                'beneficiary_id' => 'BENE_1_1',
                'beneficiary_status' => 'VERIFIED',
            ], 200),
        ]);

        $res = $this->actingAs($user)->post(route('wallet.bank-accounts.store'), [
            'account_holder_name' => 'Aarav Sharma',
            'account_number' => '123456789012',
            'account_number_confirmation' => '123456789012',
            'ifsc_code' => 'hdfc0000123',
            'bank_name' => 'HDFC Bank',
        ]);

        $res->assertRedirect(route('wallet.bank-accounts'));
        $res->assertSessionHas('success');

        $account = BankAccount::where('user_id', $user->id)->firstOrFail();
        $this->assertTrue($account->is_verified);
        $this->assertSame('HDFC0000123', $account->ifsc_code);
        $this->assertSame('XXXXXX9012', $account->masked_account_number);
        $this->assertSame('123456789012', $account->account_number); // decrypted via cast
        $this->assertSame('AARAV SHARMA', $account->name_at_bank);
        $this->assertNotNull($account->cashfree_beneficiary_id);

        // Confirm raw DB column is encrypted (not stored in plaintext!)
        $rawAccountNumber = DB::table('bank_accounts')->where('id', $account->id)->value('account_number');
        $this->assertNotSame('123456789012', $rawAccountNumber);
        $this->assertStringNotContainsString('123456789012', (string) $rawAccountNumber);

        // Another user cannot verify or delete this bank account (authorization)
        $this->actingAs($otherUser)->delete(route('wallet.bank-accounts.destroy', $account))
            ->assertForbidden();
    }

    public function test_successful_payout_reserves_and_finalizes_balance_via_webhook(): void
    {
        $user = User::factory()->create(['phone' => '9876543210']);
        $wallet = $user->wallet;
        $wallet->update(['balance' => 100000]); // ₹1,000.00

        $account = BankAccount::create([
            'user_id' => $user->id,
            'account_holder_name' => 'Aarav Sharma',
            'account_number' => '123456789012',
            'account_number_last4' => '9012',
            'account_number_hash' => BankAccount::makeAccountHash('123456789012', 'HDFC0000123'),
            'ifsc_code' => 'HDFC0000123',
            'bank_name' => 'HDFC Bank',
            'is_verified' => true,
            'verification_status' => BankAccount::VERIFICATION_VERIFIED,
            'cashfree_beneficiary_id' => 'BENE_TEST_1',
        ]);

        Http::fake([
            'https://sandbox.cashfree.com/payout/transfers' => Http::sequence()
                ->push([
                    'cf_transfer_id' => 'CF_TR_001',
                    'status' => 'RECEIVED',
                    'status_description' => 'Transfer received for processing',
                ], 200)
                ->push([
                    'cf_transfer_id' => 'CF_TR_001',
                    'status' => 'SUCCESS',
                    'transfer_utr' => 'UTR9988776655',
                    'status_description' => 'Transfer completed successfully',
                ], 200),
            'https://sandbox.cashfree.com/payout/transfers?transfer_id=*' => Http::response([
                'cf_transfer_id' => 'CF_TR_001',
                'status' => 'SUCCESS',
                'transfer_utr' => 'UTR9988776655',
                'status_description' => 'Transfer completed successfully',
            ], 200),
        ]);

        $idempotencyKey = (string) Str::uuid();

        $res = $this->actingAs($user)->post(route('wallet.withdraw.store'), [
            'bank_account_id' => $account->id,
            'amount' => '400.00',
            'idempotency_key' => $idempotencyKey,
        ]);

        $res->assertRedirect(route('wallet.withdraw'));
        $res->assertSessionHas('info');

        // Available balance reduced to ₹600.00, Reserved balance is ₹400.00
        $wallet->refresh();
        $this->assertSame(60000, $wallet->balance);
        $this->assertSame(40000, $wallet->reserved_balance);

        $withdrawal = Withdrawal::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(Withdrawal::STATUS_PROCESSING, $withdrawal->status);
        $this->assertSame('CF_TR_001', $withdrawal->cashfree_reference_id);

        // Cashfree Payouts V2 Webhook confirms SUCCESS
        $webhookBody = json_encode([
            'type' => 'TRANSFER_SUCCESS',
            'data' => [
                'transfer_id' => $withdrawal->transfer_id,
                'cf_transfer_id' => 'CF_TR_001',
                'status' => 'SUCCESS',
                'transfer_utr' => 'UTR9988776655',
            ],
        ], JSON_THROW_ON_ERROR);

        $timestamp = (string) now()->getTimestampMs();
        $signature = $this->signWebhook($webhookBody, $timestamp, 'TEST_PAYOUT_CLIENT_SECRET');

        $this->call(
            'POST',
            route('webhooks.cashfree.payout'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            $webhookBody
        )->assertOk()->assertJson([
            'status' => 'ok',
            'withdrawal_status' => 'completed',
        ]);

        // Reserved balance finalized to 0; available balance remains ₹600.00
        $wallet->refresh();
        $withdrawal->refresh();
        $this->assertSame(60000, $wallet->balance);
        $this->assertSame(0, $wallet->reserved_balance);
        $this->assertSame(Withdrawal::STATUS_COMPLETED, $withdrawal->status);
        $this->assertSame('UTR9988776655', $withdrawal->utr);
        $this->assertSame(WalletTransaction::STATUS_COMPLETED, $withdrawal->transaction->status);
    }

    public function test_failed_payout_releases_reserved_balance_back_to_wallet(): void
    {
        $user = User::factory()->create(['phone' => '9876543210']);
        $wallet = $user->wallet;
        $wallet->update(['balance' => 50000]); // ₹500.00

        $account = BankAccount::create([
            'user_id' => $user->id,
            'account_holder_name' => 'Aarav Sharma',
            'account_number' => '123456789012',
            'account_number_last4' => '9012',
            'account_number_hash' => BankAccount::makeAccountHash('123456789012', 'HDFC0000123'),
            'ifsc_code' => 'HDFC0000123',
            'bank_name' => 'HDFC Bank',
            'is_verified' => true,
            'verification_status' => BankAccount::VERIFICATION_VERIFIED,
            'cashfree_beneficiary_id' => 'BENE_TEST_FAIL',
        ]);

        Http::fake([
            'https://sandbox.cashfree.com/payout/transfers' => Http::response([
                'code' => 'transfer_rejected',
                'message' => 'Beneficiary bank account closed',
                'status' => 'REJECTED',
            ], 400),
        ]);

        $res = $this->actingAs($user)->post(route('wallet.withdraw.store'), [
            'bank_account_id' => $account->id,
            'amount' => '300.00',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $res->assertRedirect(route('wallet.withdraw'));
        $res->assertSessionHas('error');

        // Reserved funds completely released back to available balance!
        $wallet->refresh();
        $this->assertSame(50000, $wallet->balance);
        $this->assertSame(0, $wallet->reserved_balance);

        $withdrawal = Withdrawal::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(Withdrawal::STATUS_FAILED, $withdrawal->status);
        $this->assertStringContainsString('Beneficiary bank account closed', (string) $withdrawal->failure_reason);
        $this->assertSame(WalletTransaction::STATUS_FAILED, $withdrawal->transaction->status);
    }

    public function test_insufficient_balance_and_duplicate_withdrawal_are_prevented(): void
    {
        $user = User::factory()->create(['phone' => '9876543210']);
        $wallet = $user->wallet;
        $wallet->update(['balance' => 20000]); // ₹200.00

        $account = BankAccount::create([
            'user_id' => $user->id,
            'account_holder_name' => 'Aarav Sharma',
            'account_number' => '123456789012',
            'account_number_last4' => '9012',
            'account_number_hash' => BankAccount::makeAccountHash('123456789012', 'HDFC0000123'),
            'ifsc_code' => 'HDFC0000123',
            'bank_name' => 'HDFC Bank',
            'is_verified' => true,
            'verification_status' => BankAccount::VERIFICATION_VERIFIED,
            'cashfree_beneficiary_id' => 'BENE_TEST_DUP',
        ]);

        // 1. Insufficient balance rejected (₹500.00 > ₹200.00)
        $this->actingAs($user)->post(route('wallet.withdraw.store'), [
            'bank_account_id' => $account->id,
            'amount' => '500.00',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect(route('wallet.withdraw'))->assertSessionHas('error');

        $this->assertSame(20000, $wallet->fresh()->balance);
        $this->assertSame(0, Withdrawal::count());

        // 2. Duplicate withdrawal using identical idempotency key is prevented
        Http::fake([
            'https://sandbox.cashfree.com/payout/transfers' => Http::response([
                'cf_transfer_id' => 'CF_TR_DUP_1',
                'status' => 'RECEIVED',
            ], 200),
        ]);

        $sameKey = (string) Str::uuid();

        $this->actingAs($user)->post(route('wallet.withdraw.store'), [
            'bank_account_id' => $account->id,
            'amount' => '100.00',
            'idempotency_key' => $sameKey,
        ])->assertRedirect(route('wallet.withdraw'));

        // Re-submitting with exact same idempotency_key must NOT create a second withdrawal or debit again
        $this->actingAs($user)->post(route('wallet.withdraw.store'), [
            'bank_account_id' => $account->id,
            'amount' => '100.00',
            'idempotency_key' => $sameKey,
        ])->assertRedirect(route('wallet.withdraw'))->assertSessionHas('info');

        $wallet->refresh();
        $this->assertSame(10000, $wallet->balance);
        $this->assertSame(10000, $wallet->reserved_balance);
        $this->assertSame(1, Withdrawal::count());
        Http::assertSentCount(1);
    }

    public function test_transaction_history_filters_and_enforces_user_isolation(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        WalletTransaction::create([
            'wallet_id' => $userA->wallet->id,
            'user_id' => $userA->id,
            'type' => WalletTransaction::TYPE_CREDIT,
            'amount' => 50000,
            'reference_id' => 'ORD_USER_A_1',
            'transaction_type' => WalletTransaction::TXN_DEPOSIT,
            'status' => WalletTransaction::STATUS_COMPLETED,
            'description' => 'Deposit A',
        ]);

        WalletTransaction::create([
            'wallet_id' => $userB->wallet->id,
            'user_id' => $userB->id,
            'type' => WalletTransaction::TYPE_CREDIT,
            'amount' => 99900,
            'reference_id' => 'ORD_USER_B_SECRET',
            'transaction_type' => WalletTransaction::TXN_DEPOSIT,
            'status' => WalletTransaction::STATUS_COMPLETED,
            'description' => 'Deposit B',
        ]);

        $this->actingAs($userA)
            ->get(route('wallet.transactions', ['type' => 'credit', 'status' => 'completed']))
            ->assertOk()
            ->assertSee('ORD_USER_A_1')
            ->assertDontSee('ORD_USER_B_SECRET');
    }

    public function test_unverified_bank_account_cannot_be_used_for_withdrawal(): void
    {
        $user = User::factory()->create();
        $user->wallet->update(['balance' => 50000]);

        $unverified = BankAccount::create([
            'user_id' => $user->id,
            'account_holder_name' => 'Aarav Sharma',
            'account_number' => '987654321012',
            'account_number_last4' => '1012',
            'account_number_hash' => BankAccount::makeAccountHash('987654321012', 'SBIN0000123'),
            'ifsc_code' => 'SBIN0000123',
            'bank_name' => 'SBI',
            'is_verified' => false,
            'verification_status' => BankAccount::VERIFICATION_PENDING,
        ]);

        $this->actingAs($user)->post(route('wallet.withdraw.store'), [
            'bank_account_id' => $unverified->id,
            'amount' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect(route('wallet.withdraw'))->assertSessionHas('error');

        $this->assertSame(50000, $user->wallet->fresh()->balance);
        $this->assertSame(0, Withdrawal::count());
    }

    public function test_invalid_payout_webhook_signature_is_rejected_and_sync_status_handles_reversal(): void
    {
        $user = User::factory()->create();
        $wallet = $user->wallet;
        $wallet->update(['balance' => 20000, 'reserved_balance' => 30000]);

        $account = BankAccount::create([
            'user_id' => $user->id,
            'account_holder_name' => 'Aarav Sharma',
            'account_number' => '123456789012',
            'account_number_last4' => '9012',
            'account_number_hash' => BankAccount::makeAccountHash('123456789012', 'HDFC0000123'),
            'ifsc_code' => 'HDFC0000123',
            'bank_name' => 'HDFC Bank',
            'is_verified' => true,
            'verification_status' => BankAccount::VERIFICATION_VERIFIED,
        ]);

        $txn = WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'type' => WalletTransaction::TYPE_DEBIT,
            'amount' => 30000,
            'reference_id' => 'WDR_SYNC_TEST_001',
            'transaction_type' => WalletTransaction::TXN_WITHDRAWAL,
            'status' => WalletTransaction::STATUS_PROCESSING,
        ]);

        $withdrawal = Withdrawal::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'bank_account_id' => $account->id,
            'wallet_transaction_id' => $txn->id,
            'amount' => 30000,
            'transfer_id' => 'WDR_SYNC_TEST_001',
            'status' => Withdrawal::STATUS_PROCESSING,
        ]);

        // Invalid payout webhook signature rejected with 401
        $body = json_encode(['data' => ['transfer_id' => 'WDR_SYNC_TEST_001']], JSON_THROW_ON_ERROR);
        $this->call(
            'POST',
            route('webhooks.cashfree.payout'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) now()->getTimestampMs(),
                'HTTP_X_WEBHOOK_SIGNATURE' => 'invalid-signature',
            ],
            $body
        )->assertStatus(401);

        // Sync status returns REVERSED -> releases reserved balance back to available
        Http::fake([
            'https://sandbox.cashfree.com/payout/transfers?transfer_id=WDR_SYNC_TEST_001' => Http::response([
                'cf_transfer_id' => 'CF_TR_REV_1',
                'status' => 'REVERSED',
                'status_description' => 'Beneficiary account frozen',
            ], 200),
        ]);

        $this->actingAs($user)
            ->post(route('wallet.withdraw.sync', $withdrawal))
            ->assertRedirect(route('wallet.withdraw'))
            ->assertSessionHas('error');

        $wallet->refresh();
        $withdrawal->refresh();
        $this->assertSame(50000, $wallet->balance);
        $this->assertSame(0, $wallet->reserved_balance);
        $this->assertSame(Withdrawal::STATUS_REVERSED, $withdrawal->status);
    }

    public function test_bank_account_falls_back_to_payout_beneficiary_verification_when_verification_suite_disabled(): void
    {
        $user = User::factory()->create(['phone' => '9876543210']);

        Http::fake([
            'https://sandbox.cashfree.com/verification/bank-account/sync' => Http::response([
                'message' => 'Verification Suite is not enabled for this account',
            ], 403),
            'https://sandbox.cashfree.com/payout/beneficiary' => Http::response([
                'beneficiary_id' => 'BENE_' . $user->id . '_1',
                'beneficiary_status' => 'VERIFIED',
            ], 200),
        ]);

        $res = $this->actingAs($user)->post(route('wallet.bank-accounts.store'), [
            'account_holder_name' => 'Bandhi Dharish',
            'account_number' => '123456789012',
            'account_number_confirmation' => '123456789012',
            'ifsc_code' => 'SBIN0000784',
            'bank_name' => 'SBI',
        ]);

        $res->assertRedirect(route('wallet.bank-accounts'))
            ->assertSessionHas('success');

        $account = BankAccount::where('user_id', $user->id)->firstOrFail();
        $this->assertTrue($account->is_verified);
        $this->assertSame(BankAccount::VERIFICATION_VERIFIED, $account->verification_status);
        $this->assertNotNull($account->cashfree_beneficiary_id);
    }

    public function test_sync_status_releases_reserved_balance_when_cashfree_returns_404_transfer_not_found(): void
    {
        $user = User::factory()->create();
        $wallet = $user->wallet;
        $wallet->update(['balance' => 9000, 'reserved_balance' => 1000]);

        $account = BankAccount::create([
            'user_id' => $user->id,
            'account_holder_name' => 'Aarav Sharma',
            'account_number' => '000100289877623',
            'account_number_last4' => '7623',
            'account_number_hash' => BankAccount::makeAccountHash('000100289877623', 'SBIN0008752'),
            'ifsc_code' => 'SBIN0008752',
            'bank_name' => 'SBI',
            'is_verified' => true,
            'verification_status' => BankAccount::VERIFICATION_VERIFIED,
        ]);

        $txn = WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'type' => WalletTransaction::TYPE_DEBIT,
            'amount' => 1000,
            'reference_id' => 'WDR_MISSING_404_001',
            'transaction_type' => WalletTransaction::TXN_WITHDRAWAL,
            'status' => WalletTransaction::STATUS_PROCESSING,
        ]);

        $withdrawal = Withdrawal::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'bank_account_id' => $account->id,
            'wallet_transaction_id' => $txn->id,
            'amount' => 1000,
            'transfer_id' => 'WDR_MISSING_404_001',
            'status' => Withdrawal::STATUS_PROCESSING,
        ]);

        Http::fake([
            'https://sandbox.cashfree.com/payout/transfers?transfer_id=WDR_MISSING_404_001' => Http::response([
                'type' => 'invalid_request_error',
                'code' => 'transfer_id_not_found',
                'message' => 'transfer_id does not exist',
            ], 404),
        ]);

        $this->actingAs($user)
            ->post(route('wallet.withdraw.sync', $withdrawal))
            ->assertRedirect(route('wallet.withdraw'))
            ->assertSessionHas('error');

        $wallet->refresh();
        $withdrawal->refresh();
        $this->assertSame(10000, $wallet->balance);
        $this->assertSame(0, $wallet->reserved_balance);
        $this->assertSame(Withdrawal::STATUS_FAILED, $withdrawal->status);
        $this->assertSame(WalletTransaction::STATUS_FAILED, $withdrawal->transaction->status);
    }

    public function test_all_wallet_blade_views_render_cleanly_for_authenticated_user(): void
    {
        $user = User::factory()->create(['phone' => '9876543210']);
        $user->wallet->update(['balance' => 150000]);

        $this->actingAs($user)->get(route('wallet.index'))->assertOk()->assertSee('₹1,500.00');
        $this->actingAs($user)->get(route('wallet.add-money'))->assertOk()->assertSee('Add Money to Wallet');
        $this->actingAs($user)->get(route('wallet.bank-accounts'))->assertOk()->assertSee('Bank Account Management');
        $this->actingAs($user)->get(route('wallet.withdraw'))->assertOk()->assertSee('Withdraw Money');
        $this->actingAs($user)->get(route('wallet.transactions'))->assertOk()->assertSee('Transaction History');
    }
}
