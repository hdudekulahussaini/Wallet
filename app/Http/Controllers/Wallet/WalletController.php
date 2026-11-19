<?php

namespace App\Http\Controllers\Wallet;

use App\Http\Controllers\Controller;
use App\Http\Resources\WalletBalanceResource;
use App\Models\WalletTransaction;
use App\Services\CashfreePaymentService;
use App\Services\CashfreePayoutService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly CashfreePaymentService $paymentService,
        private readonly CashfreePayoutService $payoutService
    ) {
        $this->middleware('auth');
    }

    /**
     * Display the responsive Wallet Dashboard.
     */
    public function index(Request $request): View
    {
        $summary = $this->walletService->getDashboardSummary($request->user());

        return view('wallet.index', [
            'wallet' => $summary['wallet'],
            'availableBalancePaise' => $summary['available_balance_paise'],
            'reservedBalancePaise' => $summary['reserved_balance_paise'],
            'totalAddedPaise' => $summary['total_added_paise'],
            'totalWithdrawnPaise' => $summary['total_withdrawn_paise'],
            'pendingWithdrawalsCount' => $summary['pending_withdrawals_count'],
            'verifiedBankAccountsCount' => $summary['verified_bank_accounts_count'],
            'totalBankAccountsCount' => $summary['total_bank_accounts_count'],
            'recentTransactions' => $summary['recent_transactions'],
            'recentWithdrawals' => $summary['recent_withdrawals'],
            'cashfreeEnv' => config('cashfree.env'),
            'pgConfigured' => $this->paymentService->isConfigured(),
            'payoutConfigured' => $this->payoutService->isPayoutConfigured(),
        ]);
    }

    /**
     * Return authenticated user's live wallet balance as JSON.
     */
    public function balance(Request $request): WalletBalanceResource
    {
        $wallet = $this->walletService->getOrCreateWallet($request->user());

        return new WalletBalanceResource($wallet);
    }

    /**
     * Display filterable paginated wallet transaction history.
     */
    public function transactions(Request $request): View
    {
        $wallet = $this->walletService->getOrCreateWallet($request->user());

        $query = WalletTransaction::where('wallet_id', $wallet->id)
            ->where('user_id', $request->user()->id);

        if ($request->filled('type') && in_array($request->string('type')->toString(), ['credit', 'debit'], true)) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->filled('status') && in_array($request->string('status')->toString(), ['pending', 'processing', 'completed', 'failed'], true)) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('transaction_type') && in_array($request->string('transaction_type')->toString(), ['deposit', 'withdrawal', 'withdrawal_reversal'], true)) {
            $query->where('transaction_type', $request->string('transaction_type')->toString());
        }

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            $query->where(function ($q) use ($search): void {
                $q->where('reference_id', 'like', '%' . $search . '%')
                    ->orWhere('gateway_reference_id', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%');
            });
        }

        $transactions = $query->latest('id')->paginate(15)->withQueryString();

        return view('wallet.transactions', [
            'wallet' => $wallet,
            'transactions' => $transactions,
        ]);
    }
}
