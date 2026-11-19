<?php

namespace App\Http\Controllers\Wallet;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StoreBankAccountRequest;
use App\Models\BankAccount;
use App\Services\CashfreePayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class BankAccountController extends Controller
{
    public function __construct(
        private readonly CashfreePayoutService $payoutService
    ) {
        $this->middleware('auth');
    }

    /**
     * Display registered bank accounts and add-account form.
     */
    public function index(Request $request): View
    {
        $bankAccounts = BankAccount::where('user_id', $request->user()->id)
            ->latest('id')
            ->get();

        return view('wallet.bank-accounts', [
            'bankAccounts' => $bankAccounts,
            'verificationConfigured' => $this->payoutService->isVerificationConfigured(),
            'payoutConfigured' => $this->payoutService->isPayoutConfigured(),
        ]);
    }

    /**
     * Validate, store encrypted bank account details, and run Cashfree Bank Account Verification.
     */
    public function store(StoreBankAccountRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        $accountNumber = preg_replace('/\s+/', '', $validated['account_number']);
        $ifscCode = strtoupper(trim($validated['ifsc_code']));
        $accountHash = BankAccount::makeAccountHash($accountNumber, $ifscCode);

        $duplicate = BankAccount::where('user_id', $user->id)
            ->where('account_number_hash', $accountHash)
            ->exists();

        if ($duplicate) {
            return redirect()->route('wallet.bank-accounts')
                ->withInput($request->except(['account_number', 'account_number_confirmation']))
                ->with('error', 'This bank account is already registered on your profile.');
        }

        $bankAccount = BankAccount::create([
            'user_id' => $user->id,
            'account_holder_name' => trim($validated['account_holder_name']),
            'account_number' => $accountNumber,
            'account_number_last4' => substr($accountNumber, -4),
            'account_number_hash' => $accountHash,
            'ifsc_code' => $ifscCode,
            'bank_name' => trim($validated['bank_name']),
            'is_verified' => false,
            'verification_status' => BankAccount::VERIFICATION_PENDING,
        ]);

        return $this->runCashfreeVerification($bankAccount, $request);
    }

    /**
     * Re-attempt Cashfree bank account verification for a pending/failed bank account.
     */
    public function verify(Request $request, BankAccount $bankAccount): RedirectResponse
    {
        $this->authorize('verify', $bankAccount);

        if ($bankAccount->is_verified) {
            return redirect()->route('wallet.bank-accounts')
                ->with('info', 'This bank account is already verified.');
        }

        return $this->runCashfreeVerification($bankAccount, $request);
    }

    /**
     * Remove a bank account if it has no linked withdrawals.
     */
    public function destroy(BankAccount $bankAccount): RedirectResponse
    {
        $this->authorize('delete', $bankAccount);

        if ($bankAccount->withdrawals()->exists()) {
            return redirect()->route('wallet.bank-accounts')
                ->with('error', 'Cannot delete a bank account that has existing withdrawal history.');
        }

        $bankAccount->delete();

        return redirect()->route('wallet.bank-accounts')
            ->with('success', 'Bank account removed successfully.');
    }

    /**
     * Execute Cashfree Bank Account Verification Sync V2 and optional Beneficiary registration.
     */
    private function runCashfreeVerification(BankAccount $bankAccount, Request $request): RedirectResponse
    {
        if (! $this->payoutService->isVerificationConfigured()) {
            return redirect()->route('wallet.bank-accounts')
                ->with('warning', 'Bank account saved securely (encrypted), but Cashfree Verification API credentials are not configured yet. Configure credentials in .env and click Verify.');
        }

        try {
            $result = $this->payoutService->verifyBankAccount(
                $bankAccount->account_number,
                $bankAccount->ifsc_code,
                $bankAccount->account_holder_name,
                $request->user()->phone
            );

            if ($result['verified']) {
                $bankAccount->update([
                    'is_verified' => true,
                    'verification_status' => BankAccount::VERIFICATION_VERIFIED,
                    'verification_reference_id' => $result['reference_id'],
                    'name_at_bank' => $result['name_at_bank'],
                    'bank_name' => $result['bank_name'] ?: $bankAccount->bank_name,
                    'branch_name' => $result['branch'],
                    'verified_at' => now(),
                ]);

                if ($this->payoutService->isPayoutConfigured()) {
                    try {
                        $this->payoutService->ensureBeneficiary($bankAccount->refresh(), $request->user());
                    } catch (Throwable $beneficiaryError) {
                        Log::warning('Bank account verified but Cashfree Payout beneficiary registration deferred', [
                            'bank_account_id' => $bankAccount->id,
                            'message' => $beneficiaryError->getMessage(),
                        ]);
                    }
                }

                return redirect()->route('wallet.bank-accounts')
                    ->with('success', sprintf(
                        'Bank account %s verified successfully via Cashfree%s.',
                        $bankAccount->masked_account_number,
                        $result['name_at_bank'] ? ' (Registered Name: ' . $result['name_at_bank'] . ')' : ''
                    ));
            }

            // Fallback to Cashfree Payouts V2 Beneficiary verification when Verification Suite is not enabled on the merchant account
            if (! empty($result['suite_unavailable']) && $this->payoutService->isPayoutConfigured()) {
                $payoutResult = $this->payoutService->verifyViaPayoutBeneficiary($bankAccount, $request->user());

                if ($payoutResult['verified']) {
                    $bankAccount->update([
                        'is_verified' => true,
                        'verification_status' => BankAccount::VERIFICATION_VERIFIED,
                        'cashfree_beneficiary_id' => $payoutResult['beneficiary_id'],
                        'verified_at' => now(),
                    ]);

                    return redirect()->route('wallet.bank-accounts')
                        ->with('success', sprintf(
                            'Bank account %s verified and registered via Cashfree Payouts (Beneficiary: %s).',
                            $bankAccount->masked_account_number,
                            $payoutResult['beneficiary_id']
                        ));
                }

                $bankAccount->update([
                    'is_verified' => false,
                    'verification_status' => BankAccount::VERIFICATION_FAILED,
                ]);

                return redirect()->route('wallet.bank-accounts')
                    ->with('error', 'Bank account saved, but Cashfree Payouts beneficiary verification failed: ' . $payoutResult['message']);
            }

            $bankAccount->update([
                'is_verified' => false,
                'verification_status' => BankAccount::VERIFICATION_FAILED,
                'verification_reference_id' => $result['reference_id'],
            ]);

            return redirect()->route('wallet.bank-accounts')
                ->with('error', 'Bank account saved, but Cashfree verification failed: ' . $result['message']);
        } catch (Throwable $e) {
            Log::error('Bank account verification exception', [
                'bank_account_id' => $bankAccount->id,
                'user_id' => $request->user()->id,
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('wallet.bank-accounts')
                ->with('warning', 'Bank account saved securely, but verification could not be completed right now: ' . $e->getMessage());
        }
    }
}
