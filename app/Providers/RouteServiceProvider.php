<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Brute-force protection for the 4-digit code space. Without these the
        // entire 10,000-value keyspace can be enumerated in minutes.
        RateLimiter::for('code-lookup', function (Request $request) {
            return Limit::perMinute(15)
                ->by($request->ip())
                ->response($this->tooManyRequests());
        });

        // Creating notes/portals is unauthenticated; cap it so the code space
        // cannot be exhausted and the unique-code generator cannot be pinned.
        RateLimiter::for('code-create', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->ip())
                ->response($this->tooManyRequests());
        });

        // Polling is expected every ~1.5s per client; allow headroom but stop abuse.
        RateLimiter::for('portal-poll', function (Request $request) {
            return Limit::perMinute(120)
                ->by($request->ip())
                ->response($this->tooManyRequests());
        });

        RateLimiter::for('portal-action', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->ip())
                ->response($this->tooManyRequests());
        });

        // A 500 MB upload is sent in 5 MB chunks (100 requests), so allow bursts.
        RateLimiter::for('upload', function (Request $request) {
            return Limit::perMinute(300)
                ->by($request->ip())
                ->response($this->tooManyRequests());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Build the 429 response shared by the custom rate limiters.
     */
    private function tooManyRequests(): \Closure
    {
        return function (Request $request, array $headers) {
            $message = 'Too many attempts. Please wait and try again.';

            if ($request->expectsJson()) {
                return response()->json(['status' => 'error', 'message' => $message], 429, $headers);
            }

            return response($message, 429, $headers);
        };
    }
}
