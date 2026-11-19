<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        RateLimiter::for('wallet-payments', function (Request $request): Limit {
            return Limit::perMinute(15)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('wallet-withdrawals', function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('wallet-bank-accounts', function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('cashfree-webhooks', function (Request $request): Limit {
            return Limit::perMinute(120)->by($request->ip());
        });
    }
}
