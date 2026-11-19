@extends('layouts.app')

@section('title', 'Bank Account Management')

@section('content')
<div class="container">

    @if (!$verificationConfigured)
        <div class="alert alert-warning border-0 small mb-4">
            Cashfree Verification credentials are not set in <code>.env</code>. Accounts will be saved as pending until configured.
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="surface-card p-4">
                <h6 class="fw-semibold mb-3">Add Bank Account</h6>
                <form method="POST" action="{{ route('wallet.bank-accounts.store') }}" id="bankAccountForm" autocomplete="off">
                    @csrf

                    <div class="mb-3">
                        <label for="account_holder_name" class="form-label small fw-semibold">Account Holder Name</label>
                        <input
                            type="text"
                            id="account_holder_name"
                            name="account_holder_name"
                            class="form-control @error('account_holder_name') is-invalid @enderror"
                            value="{{ old('account_holder_name') }}"
                            autocomplete="off"
                            placeholder="As per bank records"
                            required
                        >
                        @error('account_holder_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="account_number" class="form-label small fw-semibold">Account Number</label>
                        <input
                            type="text"
                            id="account_number"
                            name="account_number"
                            class="form-control mono @error('account_number') is-invalid @enderror"
                            inputmode="numeric"
                            minlength="9"
                            maxlength="18"
                            autocomplete="off"
                            placeholder="9 to 18 digits"
                            required
                        >
                        @error('account_number')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="account_number_confirmation" class="form-label small fw-semibold">Confirm Account Number</label>
                        <input
                            type="text"
                            id="account_number_confirmation"
                            name="account_number_confirmation"
                            class="form-control mono"
                            inputmode="numeric"
                            minlength="9"
                            maxlength="18"
                            autocomplete="off"
                            placeholder="Re-enter account number"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label for="ifsc_code" class="form-label small fw-semibold">IFSC Code</label>
                        <input
                            type="text"
                            id="ifsc_code"
                            name="ifsc_code"
                            class="form-control text-uppercase mono @error('ifsc_code') is-invalid @enderror"
                            value="{{ old('ifsc_code') }}"
                            maxlength="11"
                            pattern="[A-Za-z]{4}0[A-Za-z0-9]{6}"
                            placeholder="HDFC0000123"
                            required
                        >
                        @error('ifsc_code')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <label for="bank_name" class="form-label small fw-semibold">Bank Name</label>
                        <input
                            type="text"
                            id="bank_name"
                            name="bank_name"
                            class="form-control @error('bank_name') is-invalid @enderror"
                            value="{{ old('bank_name') }}"
                            placeholder="e.g., HDFC Bank"
                            required
                        >
                        @error('bank_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary fw-semibold">Save &amp; Verify</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="surface-card">
                <div class="px-3 py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-semibold">Saved Accounts</h6>
                    <span class="small text-muted">{{ $bankAccounts->count() }} total</span>
                </div>
                <div class="list-group list-group-flush">
                    @forelse ($bankAccounts as $account)
                        <div class="list-group-item px-3 py-3 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
                            <div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-semibold">{{ $account->bank_name }}</span>
                                    @if ($account->is_verified)
                                        <span class="badge bg-success">Verified</span>
                                    @elseif ($account->verification_status === 'failed')
                                        <span class="badge bg-danger">Failed</span>
                                    @else
                                        <span class="badge bg-warning text-dark">Pending</span>
                                    @endif
                                </div>
                                <div class="small text-muted mt-1">
                                    {{ $account->account_holder_name }}
                                    &middot; <span class="mono text-body">{{ $account->masked_account_number }}</span>
                                    &middot; <span class="mono">{{ $account->ifsc_code }}</span>
                                </div>
                            </div>

                            <div class="d-flex gap-2">
                                @if (!$account->is_verified)
                                    <form method="POST" action="{{ route('wallet.bank-accounts.verify', $account) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-success">Verify</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('wallet.bank-accounts.destroy', $account) }}" onsubmit="return confirm('Remove this bank account?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-5 text-muted small">
                            No bank accounts added yet.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
