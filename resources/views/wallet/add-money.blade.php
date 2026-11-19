@extends('layouts.app')

@section('title', 'Add Money to Wallet')

@section('content')
<div class="container">
    <div class="d-flex justify-content-end mb-3">
        <div class="surface-card px-3 py-2 small">
            <span class="text-muted me-1">Balance:</span>
            <strong id="headerWalletBalance">{{ $wallet->formatted_balance }}</strong>
        </div>
    </div>

    @if (!$pgConfigured)
        <div class="alert alert-warning border-0 small mb-4">
            Set <code>CASHFREE_CLIENT_ID</code> and <code>CASHFREE_CLIENT_SECRET</code> in <code>.env</code> to enable Cashfree Checkout.
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="surface-card p-4">
                <div id="paymentFeedback" class="alert d-none small" role="alert"></div>

                <form
                    id="addMoneyForm"
                    data-order-url="{{ route('wallet.add-money.order') }}"
                    data-checkout-mode="{{ $checkoutMode }}"
                    novalidate
                >
                    @csrf
                    <div class="mb-3">
                        <label for="amount" class="form-label small fw-semibold">Amount (INR)</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-light">₹</span>
                            <input
                                type="number"
                                step="0.01"
                                min="{{ $minDepositRupees }}"
                                max="{{ $maxDepositRupees }}"
                                class="form-control fw-semibold"
                                id="amount"
                                name="amount"
                                placeholder="500.00"
                                required
                            >
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            @foreach ([100, 500, 1000, 2500, 5000] as $preset)
                                <button type="button" class="btn btn-sm amount-chip" data-amount="{{ $preset }}">
                                    +₹{{ number_format($preset) }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="phone" class="form-label small fw-semibold">Mobile Number</label>
                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            maxlength="10"
                            pattern="[0-9]{10}"
                            class="form-control"
                            value="{{ old('phone', auth()->user()->phone) }}"
                            placeholder="10-digit mobile number"
                            required
                        >
                    </div>

                    <div class="d-grid">
                        <button type="submit" id="payNowBtn" class="btn btn-primary btn-lg fw-semibold">
                            <span id="payBtnText">Pay with Cashfree</span>
                            <span id="payBtnSpinner" class="spinner-border spinner-border-sm d-none ms-2" role="status" aria-hidden="true"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="surface-card">
                <div class="px-3 py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-semibold">Recent Deposits</h6>
                    <a href="{{ route('wallet.transactions', ['transaction_type' => 'deposit']) }}" class="small text-decoration-none">View all</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-muted">
                            <tr>
                                <th>Order ID</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recentDeposits as $deposit)
                                <tr>
                                    <td>
                                        <div class="mono fw-medium">{{ $deposit->reference_id }}</div>
                                        <div class="small text-muted">{{ $deposit->created_at->format('d M, h:i A') }}</div>
                                    </td>
                                    <td class="fw-semibold text-success">+{{ $deposit->formatted_amount }}</td>
                                    <td>
                                        <span class="badge {{ $deposit->status_badge_class }}">{{ ucfirst($deposit->status) }}</span>
                                        @if (!empty($deposit->metadata['failure_reason']))
                                            <div class="small text-danger mt-1">{{ $deposit->metadata['failure_reason'] }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if ($deposit->status === 'pending' || $deposit->status === 'processing')
                                            <form method="POST" action="{{ route('wallet.add-money.verify', $deposit->reference_id) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Verify</button>
                                            </form>
                                        @else
                                            <span class="text-muted small">&mdash;</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted small">No deposits yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ $jsSdkUrl }}"></script>
<script src="{{ asset('js/wallet-checkout.js') }}"></script>
@endpush
