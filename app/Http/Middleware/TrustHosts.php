<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;

class TrustHosts extends Middleware
{
    /**
     * Get the host patterns that should be trusted.
     *
     * @return array<int, string|null>
     */
    public function hosts(): array
    {
        // Only constrain hosts in production; local development and tests use
        // arbitrary hostnames (localhost, 127.0.0.1, container names).
        if (!app()->environment('production')) {
            return ['*'];
        }

        return [
            $this->allSubdomainsOfApplicationUrl(),
        ];
    }
}
