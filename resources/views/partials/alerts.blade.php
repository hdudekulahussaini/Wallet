<div class="container">
    @foreach (['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'] as $key => $alertType)
        @if (session($key))
            <div class="alert alert-{{ $alertType }} alert-dismissible fade show shadow-sm border-0 d-flex align-items-center" role="alert">
                <i class="bi {{ $key === 'success' ? 'bi-check-circle-fill' : ($key === 'error' ? 'bi-exclamation-triangle-fill' : 'bi-info-circle-fill') }} me-2 fs-5"></i>
                <div>{{ session($key) }}</div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif
    @endforeach

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0" role="alert">
            <div class="fw-semibold mb-1"><i class="bi bi-exclamation-octagon-fill me-1"></i> Please correct the following errors:</div>
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif
</div>
