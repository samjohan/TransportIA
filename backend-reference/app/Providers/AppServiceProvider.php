<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Brute-force protection on POST /api/login — Auth::attempt() has
        // no built-in attempt limit, so without this a script can guess
        // passwords indefinitely. Keyed on email+IP rather than IP alone:
        // rotating IPs doesn't reset the limit against one targeted
        // account, and one attacker spraying many emails from a single IP
        // still gets capped per email instead of sharing one global bucket.
        // Applied via `throttle:login` on the route in routes/api.php.
        RateLimiter::for('login', function (Request $request) {
            $key = Str::lower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });
    }
}
