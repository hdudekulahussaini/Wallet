@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="container">
    @if (!$pgConfigured || !$payoutConfigured)
        <div class="alert alert-warning border-0 small mb-4" role="alert">
            <i class="bi bi-exclamation-circle me-1"></i>
            Cashfree credentials missing in <code>.env</code>:
            @if (!$pgConfigured) <code>CASHFREE_CLIENT_ID / SECRET</code> @endif
            @if (!$pgConfigured && !$payoutConfigured) &amp; @endif
            @if (!$payoutConfigured) <code>CASHFREE_PAYOUT_CLIENT_ID / SECRET</code> @endif
        </div>
    @endif

    <!-- Available Balance -->
    <div class="card balance-card mb-4" style="background: linear-gradient(135deg, #213f86 0%, #ba0000 100%) !important; color: #ffffff !important; border: 0 !important;">
        <div class="card-body p-4">
            <div class="row align-items-center g-3">
                <div class="col-md-7">
                    <div class="d-flex align-items-center gap-2 text-white-50 small mb-1">
                        <span>Available Wallet Balance</span>
                        <button type="button" id="refreshBalanceBtn" data-balance-url="{{ route('wallet.balance') }}" class="btn btn-link btn-sm text-white-50 p-0" title="Refresh balance">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                    <h1 class="display-6 fw-bold mb-0" id="liveAvailableBalance">
                        {{ \App\Models\Wallet::formatPaise($availableBalancePaise) }}
                    </h1>
                    <div class="small text-white-50 mt-1 {{ $reservedBalancePaise > 0 ? '' : 'd-none' }}">
                        Reserved for pending payouts: <strong class="text-white" id="liveReservedBalance">{{ \App\Models\Wallet::formatPaise($reservedBalancePaise) }}</strong>
                    </div>
                </div>
                <div class="col-md-5 d-flex flex-wrap gap-2 justify-content-md-end">
                    <a href="{{ route('wallet.add-money') }}" class="btn btn-light fw-semibold px-4 text-danger shadow-sm">
                        <i class="bi bi-plus-lg me-1"></i> Add Money
                    </a>
                    <a href="{{ route('wallet.withdraw') }}" class="btn btn-outline-light fw-semibold px-4">
                        <i class="bi bi-arrow-up-right me-1"></i> Withdraw
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Metrics (No repeated navigation cards) -->
    <div class="row g-3 mb-4">
        <div class="col-sm-4">
            <div class="surface-card p-3 h-100">
                <div class="metric-label">Total Added</div>
                <div class="metric-value text-success mt-1">{{ \App\Models\Wallet::formatPaise($totalAddedPaise) }}</div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="surface-card p-3 h-100">
                <div class="metric-label">Total Withdrawn</div>
                <div class="metric-value text-primary mt-1">{{ \App\Models\Wallet::formatPaise($totalWithdrawnPaise) }}</div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="surface-card p-3 h-100 d-flex justify-content-between align-items-center">
                <div>
                    <div class="metric-label">Verified Bank Accounts</div>
                    <div class="metric-value mt-1">
                        {{ $verifiedBankAccountsCount }}
                        <span class="fs-6 fw-normal text-muted">/ {{ $totalBankAccountsCount }}</span>
                    </div>
                </div>
                <a href="{{ route('wallet.bank-accounts') }}" class="btn btn-sm btn-outline-danger">Manage</a>
            </div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="row g-4">
        <div class="{{ $recentWithdrawals->isNotEmpty() ? 'col-lg-8' : 'col-12' }}">
            <div class="surface-card">
                <div class="px-3 py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-semibold">Recent Transactions</h6>
                    <a href="{{ route('wallet.transactions') }}" class="small text-decoration-none fw-medium text-danger">View all</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-muted">
                            <tr>
                                <th>Reference</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recentTransactions as $txn)
                                <tr>
                                    <td>
                                        <div class="mono fw-medium">{{ $txn->reference_id }}</div>
                                        <div class="text-muted small">{{ $txn->created_at->format('d M Y, h:i A') }}</div>
                                    </td>
                                    <td class="small">{{ ucfirst(str_replace('_', ' ', $txn->transaction_type)) }}</td>
                                    <td><span class="badge {{ $txn->status_badge_class }}">{{ ucfirst($txn->status) }}</span></td>
                                    <td class="text-end fw-semibold {{ $txn->type === 'credit' ? 'text-success' : 'text-danger' }}">
                                        {{ $txn->type === 'credit' ? '+' : '-' }}{{ $txn->formatted_amount }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted small">
                                        No transactions yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        @if ($recentWithdrawals->isNotEmpty())
            <div class="col-lg-4">
                <div class="surface-card h-100">
                    <div class="px-3 py-3 border-bottom d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-semibold">Payout Status</h6>
                        <a href="{{ route('wallet.withdraw') }}" class="small text-decoration-none fw-medium text-danger">All payouts</a>
                    </div>
                    <div class="list-group list-group-flush">
                        @foreach ($recentWithdrawals as $withdrawal)
                            <div class="list-group-item px-3 py-3 border-light-subtle">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div>
                                        <div class="fw-semibold">{{ $withdrawal->formatted_amount }}</div>
                                        <div class="small text-muted">
                                            {{ $withdrawal->bankAccount?->bank_name }} ({{ $withdrawal->bankAccount?->masked_account_number }})
                                        </div>
                                        @if ($withdrawal->utr)
                                            <div class="small text-success mono">UTR: {{ $withdrawal->utr }}</div>
                                        @elseif ($withdrawal->failure_reason)
                                            <div class="small text-danger">{{ $withdrawal->failure_reason }}</div>
                                        @endif
                                    </div>
                                    <div class="text-end">
                                        <span class="badge {{ $withdrawal->status_badge_class }}">{{ ucfirst($withdrawal->status) }}</span>
                                        @if (!$withdrawal->isTerminal())
                                            <form method="POST" action="{{ route('wallet.withdraw.sync', $withdrawal) }}" class="mt-1">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-secondary py-0 px-2">Sync</button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
