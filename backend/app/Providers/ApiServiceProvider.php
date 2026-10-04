<?php

namespace App\Providers;

use App\Support\TogoPhone;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Rate limiters of the API (ADR-14). Registered in bootstrap/app.php (withProviders).
 *
 * Many mobile users share one public IP address: the login limit is therefore keyed on the
 * account (normalised login + IP) and the per-IP ceiling stays high.
 */
class ApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('public-api', fn (Request $request) => Limit::perMinute(240)->by('ip:'.$request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by(
            $request->user() !== null ? 'user:'.$request->user()->getKey() : 'ip:'.$request->ip()
        ));

        RateLimiter::for('auth-register', fn (Request $request) => [
            Limit::perMinute(10)->by('register-minute:'.$request->ip()),
            Limit::perHour(50)->by('register-hour:'.$request->ip()),
        ]);

        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.$this->loginKey($request).'|'.$request->ip()),
            Limit::perMinute(60)->by('login-ip:'.$request->ip()),
        ]);
    }

    /**
     * "90 12 34 56" and "+22890123456" must share the same counter, and e-mails are case-insensitive.
     */
    private function loginKey(Request $request): string
    {
        $login = $request->input('login');

        if (! is_string($login)) {
            return '';
        }

        $login = trim($login);

        return str_contains($login, '@')
            ? mb_strtolower($login)
            : (TogoPhone::normalize($login) ?? mb_strtolower($login));
    }
}
