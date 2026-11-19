@extends('layouts.app')

@section('title', 'Login')

@push('styles')
<style>
    .form-control:focus, .form-check-input:focus {
        border-color: #dc3545;
        box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25);
    }
    .form-check-input:checked {
        background-color: #dc3545;
        border-color: #dc3545;
    }
</style>
@endpush

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-4">
            <div class="surface-card p-4 mt-3 border-top border-3 border-danger">
                <h5 class="fw-bold mb-1 text-danger">Sign in</h5>
                <p class="text-muted small mb-4">Access your wallet account</p>

                <form method="POST" action="{{ route('login') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="email" class="form-label small fw-semibold">Email</label>
                        <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus>
                        @error('email')
                            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label small fw-semibold">Password</label>
                        <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" name="password" required autocomplete="current-password">
                        @error('password')
                            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="remember" id="remember" {{ old('remember') ? 'checked' : '' }}>
                            <label class="form-check-label small" for="remember">Remember me</label>
                        </div>
                        @if (Route::has('password.request'))
                            <a class="small text-decoration-none text-danger" href="{{ route('password.request') }}">Forgot password?</a>
                        @endif
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-danger fw-semibold">Sign in</button>
                    </div>

                    <p class="text-center text-muted small mb-0">
                        New here? <a href="{{ route('register') }}" class="text-decoration-none fw-medium text-danger">Create account</a>
                    </p>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
