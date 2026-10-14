<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
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
        // The app runs behind a TLS-terminating proxy that reports the request
        // as https while the container still sees port 80. Left alone, Laravel
        // builds redirects and signed URLs from that (scheme + host + port),
        // producing "https://notery.cc:80". Pin generated URLs to APP_URL so
        // they no longer depend on the forwarded port.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
            URL::forceRootUrl(config('app.url'));
        }
    }
}
