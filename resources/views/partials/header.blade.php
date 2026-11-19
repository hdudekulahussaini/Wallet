<header class="app-header sticky-top d-flex align-items-center justify-content-between px-3 px-lg-4">
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-sm btn-outline-secondary border-0 d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#walletSidebar" aria-controls="walletSidebar" aria-label="Open menu">
            <i class="bi bi-list fs-4"></i>
        </button>
        <h5 class="mb-0 fw-semibold fs-6">@yield('title', 'Dashboard')</h5>
    </div>

    <div class="d-flex align-items-center gap-3">
        <div class="dropdown">
            <a class="d-flex align-items-center gap-2 text-decoration-none text-dark dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="sidebar-avatar">{{ strtoupper(substr(Auth::user()->name, 0, 1)) }}</span>
                <span class="small fw-medium d-none d-sm-inline">{{ Auth::user()->name }}</span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm border mt-2">
                <li><span class="dropdown-item-text small text-muted">{{ Auth::user()->email }}</span></li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="dropdown-item text-danger">
                            <i class="bi bi-box-arrow-right me-2"></i>Logout
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>
