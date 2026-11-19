@extends('layouts.app')

@section('title', 'Withdraw Money')

@section('content')
<div class="container">
    <div class="d-flex justify-content-end mb-3 gap-2 small">
        <div class="surface-card px-3 py-2">
            <span class="text-muted me-1">Available:</span>
            <strong class="text-success">{{ $wallet->formatted_balance }}</strong>
        </div>
        @if ($wallet->reserved_balance > 0)
            <div class="surface-card px-3 py-2">
                <span class="text-muted me-1">Reserved:</span>
                <strong class="text-warning">{{ $wallet->formatted_reserved_balance }}</strong>
            </div>
        @endif
    </div>

    @if (!$payoutConfigured)
        <div class="alert alert-warning border-0 small mb-4">
            Set <code>CASHFREE_PAYOUT_CLIENT_ID</code> and <code>CASHFREE_PAYOUT_CLIENT_SECRET</code> in <code>.env</code> to enable Cashfree Payouts.
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="surface-card p-4">
                @if ($verifiedAccounts->isEmpty())
                    <div class="text-center py-3">
                        <p class="text-muted small mb-3">
                            Add and verify a bank account before withdrawing.
                            @if ($unverifiedCount > 0)
                                ({{ $unverifiedCount }} unverified)
                            @endif
                        </p>
                        <a href="{{ route('wallet.bank-accounts') }}" class="btn btn-primary btn-sm px-3">Manage Bank Accounts</a>
                    </div>
                @else
                    <form method="POST" action="{{ route('wallet.withdraw.store') }}" id="withdrawalForm">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

                        <div class="mb-3">
                            <label for="bank_account_id" class="form-label small fw-semibold">Bank Account</label>
                            <select id="bank_account_id" name="bank_account_id" class="form-select @error('bank_account_id') is-invalid @enderror" required>
                                <option value="">Select verified account</option>
                                @foreach ($verifiedAccounts as $account)
                                    <option value="{{ $account->id }}" {{ (string) old('bank_account_id') === (string) $account->id ? 'selected' : '' }}>
                                        {{ $account->bank_name }} — {{ $account->masked_account_number }} ({{ $account->ifsc_code }})
                                    </option>
                                @endforeach
                            </select>
                            @error('bank_account_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label for="withdraw_amount" class="form-label small fw-semibold mb-0">Amount (INR)</label>
                                @if ($wallet->balance > 0)
                                    <button type="button" id="withdrawMaxBtn" class="btn btn-link btn-sm p-0 text-decoration-none" data-max="{{ \App\Models\Wallet::paiseToRupees($wallet->balance) }}">
                                        Max ({{ $wallet->formatted_balance }})
                                    </button>
                                @endif
                            </div>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-light">₹</span>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="{{ $minWithdrawalRupees }}"
                                    max="{{ \App\Models\Wallet::paiseToRupees(min($wallet->balance, (int) config('cashfree.limits.max_withdrawal_paise', 10000000))) }}"
                                    id="withdraw_amount"
                                    name="amount"
                                    class="form-control fw-semibold @error('amount') is-invalid @enderror"
                                    value="{{ old('amount') }}"
                                    placeholder="0.00"
                                    required
                                >
                            </div>
                            @error('amount')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="d-grid">
                            <button type="submit" id="withdrawSubmitBtn" class="btn btn-primary btn-lg fw-semibold" {{ $wallet->balance < (int) config('cashfree.limits.min_withdrawal_paise', 100) ? 'disabled' : '' }}>
                                Withdraw to Bank
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        <div class="col-lg-7">
            <div class="surface-card">
                <div class="px-3 py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-semibold">Withdrawal History</h6>
                    <span class="small text-muted">{{ $withdrawals->total() }} total</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-muted">
                            <tr>
                                <th>Transfer</th>
                                <th>Account</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($withdrawals as $withdrawal)
                                <tr>
                                    <td>
                                        <div class="mono fw-medium">{{ $withdrawal->transfer_id }}</div>
                                        @if ($withdrawal->utr)
                                            <div class="small text-success mono">UTR: {{ $withdrawal->utr }}</div>
                                        @endif
                                        <div class="small text-muted">{{ $withdrawal->created_at->format('d M, h:i A') }}</div>
                                    </td>
                                    <td class="small">
                                        <div>{{ $withdrawal->bankAccount?->bank_name ?? '—' }}</div>
                                        <div class="mono text-muted">{{ $withdrawal->bankAccount?->masked_account_number ?? '—' }}</div>
                                    </td>
                                    <td class="fw-semibold">{{ $withdrawal->formatted_amount }}</td>
                                    <td>
                                        <span class="badge {{ $withdrawal->status_badge_class }}">{{ ucfirst($withdrawal->status) }}</span>
                                        @if ($withdrawal->failure_reason)
                                            <div class="small text-danger mt-1">{{ $withdrawal->failure_reason }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if (!$withdrawal->isTerminal())
                                            <form method="POST" action="{{ route('wallet.withdraw.sync', $withdrawal) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Sync</button>
                                            </form>
                                        @else
                                            <span class="text-muted small">&mdash;</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted small">No withdrawals yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($withdrawals->hasPages())
                    <div class="px-3 py-2 border-top">{{ $withdrawals->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
