<?php

$environment = env('CASHFREE_ENV', 'sandbox') === 'production' ? 'production' : 'sandbox';
$isProduction = $environment === 'production';

return [
    /*
    |--------------------------------------------------------------------------
    | Cashfree Environment
    |--------------------------------------------------------------------------
    | Supported values: "sandbox", "production"
    */
    'env' => $environment,

    /*
    |--------------------------------------------------------------------------
    | Cashfree Payment Gateway Configuration (API v2025-01-01)
    |--------------------------------------------------------------------------
    */
    'payment_gateway' => [
        'client_id' => trim((string) env('CASHFREE_CLIENT_ID', '')),
        'client_secret' => trim((string) env('CASHFREE_CLIENT_SECRET', '')),
        'webhook_secret' => trim((string) (env('CASHFREE_WEBHOOK_SECRET') ?: env('CASHFREE_CLIENT_SECRET', ''))),
        'api_version' => trim((string) env('CASHFREE_API_VERSION', '2025-01-01')),
        'base_url' => $isProduction
            ? 'https://api.cashfree.com/pg'
            : 'https://sandbox.cashfree.com/pg',
        'js_sdk_url' => 'https://sdk.cashfree.com/js/v3/cashfree.js',
        'checkout_mode' => $isProduction ? 'production' : 'sandbox',
        'timeout' => (int) env('CASHFREE_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cashfree Payouts V2 Configuration (API v2024-01-01)
    |--------------------------------------------------------------------------
    | Payouts uses separate merchant credentials from Payment Gateway in
    | Cashfree Dashboard (Client ID typically starts with CF...).
    */
    'payouts' => [
        'client_id' => trim((string) env('CASHFREE_PAYOUT_CLIENT_ID', '')),
        'client_secret' => trim((string) env('CASHFREE_PAYOUT_CLIENT_SECRET', '')),
        'webhook_secret' => trim((string) (env('CASHFREE_PAYOUT_WEBHOOK_SECRET') ?: env('CASHFREE_PAYOUT_CLIENT_SECRET', ''))),
        'api_version' => trim((string) env('CASHFREE_PAYOUT_API_VERSION', '2024-01-01')),
        'base_url' => $isProduction
            ? 'https://api.cashfree.com/payout'
            : 'https://sandbox.cashfree.com/payout',
        'transfer_mode' => trim((string) env('CASHFREE_PAYOUT_TRANSFER_MODE', 'banktransfer')),
        'timeout' => (int) env('CASHFREE_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cashfree Verification Suite (Bank Account Verification Sync V2)
    |--------------------------------------------------------------------------
    | Used for penny-drop bank account verification. Falls back to Payouts
    | credentials when Verification-specific credentials are not provided.
    */
    'verification' => [
        'client_id' => trim((string) (env('CASHFREE_VERIFICATION_CLIENT_ID') ?: env('CASHFREE_PAYOUT_CLIENT_ID', ''))),
        'client_secret' => trim((string) (env('CASHFREE_VERIFICATION_CLIENT_SECRET') ?: env('CASHFREE_PAYOUT_CLIENT_SECRET', ''))),
        'base_url' => $isProduction
            ? 'https://api.cashfree.com/verification'
            : 'https://sandbox.cashfree.com/verification',
        'timeout' => (int) env('CASHFREE_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Wallet Transaction Limits (in paise)
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'min_deposit_paise' => (int) env('WALLET_MIN_DEPOSIT_PAISE', 100),         // ₹1.00
        'max_deposit_paise' => (int) env('WALLET_MAX_DEPOSIT_PAISE', 10000000),    // ₹1,00,000.00
        'min_withdrawal_paise' => (int) env('WALLET_MIN_WITHDRAWAL_PAISE', 100),   // ₹1.00
        'max_withdrawal_paise' => (int) env('WALLET_MAX_WITHDRAWAL_PAISE', 10000000), // ₹1,00,000.00
    ],
];
