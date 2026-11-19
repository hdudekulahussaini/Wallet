<nav class="navbar navbar-expand navbar-clean">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="{{ url('/') }}">
            <i class="bi bi-wallet2 text-primary fs-5"></i>
            <span>{{ config('app.name', 'WalletPay') }}</span>
        </a>
        <ul class="navbar-nav ms-auto align-items-center gap-2">
            @if (Route::has('login'))
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('login') ? 'active' : '' }}" href="{{ route('login') }}">Login</a>
                </li>
            @endif
            @if (Route::has('register'))
                <li class="nav-item">
                    <a class="btn btn-primary btn-sm px-3" href="{{ route('register') }}">Sign Up</a>
                </li>
            @endif
        </ul>
    </div>
</nav>
