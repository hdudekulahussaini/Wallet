<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Wallet') — {{ config('app.name', 'WalletPay') }}</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('css/wallet.css') }}?v={{ file_exists(public_path('css/wallet.css')) ? filemtime(public_path('css/wallet.css')) : time() }}" rel="stylesheet">

    @stack('styles')
</head>
<body>
    @auth
        <div class="app-shell">
            @include('partials.sidebar')

            <div class="app-content d-flex flex-column min-vh-100">
                @include('partials.header')

                <main class="py-4 px-lg-3 flex-grow-1">
                    @include('partials.alerts')

                    @yield('content')
                </main>

                <footer class="py-3 text-center text-muted small">
                    &copy; {{ date('Y') }} {{ config('app.name', 'WalletPay') }}
                </footer>
            </div>
        </div>
    @else
        @include('partials.navbar')

        <main class="py-4 flex-grow-1">
            @include('partials.alerts')

            @yield('content')
        </main>

        <footer class="py-3 text-center text-muted small">
            &copy; {{ date('Y') }} {{ config('app.name', 'WalletPay') }}
        </footer>
    @endauth

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="{{ asset('js/wallet.js') }}"></script>

    @stack('scripts')
</body>
</html>
