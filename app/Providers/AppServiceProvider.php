<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Cross-cutting framework bindings only. Domain bindings belong to modules.
    }

    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $email = Str::lower(trim((string) $request->input('email')));
            $accountDigest = hash('sha256', $email);
            $sourceIp = $request->ip() ?? 'unknown';

            return [
                Limit::perMinute(5)->by('login:account:'.$accountDigest),
                Limit::perMinute(20)->by('login:ip:'.$sourceIp),
            ];
        });

        RateLimiter::for('operations', function (Request $request): Limit {
            return Limit::perMinute(60)->by('operations:ip:'.($request->ip() ?? 'unknown'));
        });
    }
}
