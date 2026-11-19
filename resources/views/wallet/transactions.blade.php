@extends('layouts.app')

@section('title', 'Transaction History')

@section('content')
<div class="container">
    <div class="d-flex justify-content-end mb-3">
        <div class="surface-card px-3 py-2 small">
            <span class="text-muted me-1">Balance:</span>
            <strong>{{ $wallet->formatted_balance }}</strong>
        </div>
    </div>

    <div class="surface-card p-3 mb-3">
        <form method="GET" action="{{ route('wallet.transactions') }}" class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control form-control-sm" value="{{ request('search') }}" placeholder="Search reference or description...">
            </div>
            <div class="col-sm-4 col-md-2">
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Flows</option>
                    <option value="credit" {{ request('type') === 'credit' ? 'selected' : '' }}>Credit (+)</option>
                    <option value="debit" {{ request('type') === 'debit' ? 'selected' : '' }}>Debit (-)</option>
                </select>
            </div>
            <div class="col-sm-4 col-md-2">
                <select name="transaction_type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <option value="deposit" {{ request('transaction_type') === 'deposit' ? 'selected' : '' }}>Deposit</option>
                    <option value="withdrawal" {{ request('transaction_type') === 'withdrawal' ? 'selected' : '' }}>Withdrawal</option>
                    <option value="withdrawal_reversal" {{ request('transaction_type') === 'withdrawal_reversal' ? 'selected' : '' }}>Reversal</option>
                </select>
            </div>
            <div class="col-sm-4 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                    <option value="processing" {{ request('status') === 'processing' ? 'selected' : '' }}>Processing</option>
                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1">Filter</button>
                @if (request()->hasAny(['search', 'type', 'transaction_type', 'status']))
                    <a href="{{ route('wallet.transactions') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                @endif
            </div>
        </form>
    </div>

    <div class="surface-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small text-muted">
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Type</th>
                        <th>Details</th>
                        <th>Status</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transactions as $txn)
                        <tr>
                            <td class="small text-nowrap text-muted">{{ $txn->created_at->format('d M Y, h:i A') }}</td>
                            <td class="mono fw-medium">{{ $txn->reference_id }}</td>
                            <td class="small">{{ ucfirst(str_replace('_', ' ', $txn->transaction_type)) }}</td>
                            <td class="small text-muted">
                                {{ $txn->description ?? '—' }}
                                @if (!empty($txn->metadata['utr']))
                                    <span class="text-success mono ms-1">(UTR: {{ $txn->metadata['utr'] }})</span>
                                @elseif (!empty($txn->metadata['failure_reason']))
                                    <div class="text-danger">{{ $txn->metadata['failure_reason'] }}</div>
                                @endif
                            </td>
                            <td><span class="badge {{ $txn->status_badge_class }}">{{ ucfirst($txn->status) }}</span></td>
                            <td class="text-end fw-semibold text-nowrap {{ $txn->type === 'credit' ? 'text-success' : 'text-danger' }}">
                                {{ $txn->type === 'credit' ? '+' : '-' }}{{ $txn->formatted_amount }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted small">No transactions found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($transactions->hasPages())
            <div class="px-3 py-2 border-top">{{ $transactions->links() }}</div>
        @endif
    </div>
</div>
@endsection
