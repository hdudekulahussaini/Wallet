<aside class="wallet-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="walletSidebar" aria-labelledby="walletSidebarLabel">
    <div class="offcanvas-header border-bottom d-lg-none">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2 text-decoration-none text-dark" id="walletSidebarLabel" href="{{ route('wallet.index') }}">
            <i class="bi bi-wallet2 text-primary fs-5"></i>
            <span>{{ config('app.name', 'WalletPay') }}</span>
        </a>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#walletSidebar" aria-label="Close"></button>
    </div>

    <div class="sidebar-inner d-flex flex-column h-100">
        <div class="sidebar-brand d-none d-lg-flex align-items-center px-3 border-bottom">
            <a class="fw-bold d-flex align-items-center gap-2 text-decoration-none text-dark fs-6" href="{{ route('wallet.index') }}">
                <i class="bi bi-wallet2 text-primary fs-5"></i>
                <span>{{ config('app.name', 'WalletPay') }}</span>
            </a>
        </div>

        <nav class="nav flex-column p-3 gap-1">
            <a class="sidebar-link {{ request()->routeIs('wallet.index') ? 'active' : '' }}" href="{{ route('wallet.index') }}">
                <i class="bi bi-grid"></i>
                <span>Dashboard</span>
            </a>
            <a class="sidebar-link {{ request()->routeIs('wallet.add-money*') ? 'active' : '' }}" href="{{ route('wallet.add-money') }}">
                <i class="bi bi-plus-circle"></i>
                <span>Add Money</span>
            </a>
            <a class="sidebar-link {{ request()->routeIs('wallet.withdraw*') ? 'active' : '' }}" href="{{ route('wallet.withdraw') }}">
                <i class="bi bi-arrow-up-right-circle"></i>
                <span>Withdraw</span>
            </a>
            <a class="sidebar-link {{ request()->routeIs('wallet.bank-accounts*') ? 'active' : '' }}" href="{{ route('wallet.bank-accounts') }}">
                <i class="bi bi-bank"></i>
                <span>Bank Accounts</span>
            </a>
            <a class="sidebar-link {{ request()->routeIs('wallet.transactions') ? 'active' : '' }}" href="{{ route('wallet.transactions') }}">
                <i class="bi bi-clock-history"></i>
                <span>Transactions</span>
            </a>
        </nav>
    </div>
</aside>
