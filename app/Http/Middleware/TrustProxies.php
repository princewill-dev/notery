<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * The app runs behind a TLS-terminating proxy (Docker network / platform
     * load balancer), so forwarded headers must be trusted for correct scheme
     * detection and signed URLs. Tighten with TRUSTED_PROXIES if the app is
     * ever exposed directly.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = '*';

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Let the parent register the trusted proxies first, so the forwarded
        // headers are readable in the callback.
        return parent::handle($request, function (Request $request) use ($next) {
            $this->normalizeForwardedPort($request);

            return $next($request);
        });
    }

    /**
     * Drop a forwarded port that contradicts the forwarded scheme.
     *
     * A TLS-terminating proxy forwards plain HTTP to this container on port 80
     * while still reporting the client scheme as https. Trusting that port
     * gives the request an origin of "https://host:80": redirects point at
     * port 80, and the `signed` middleware — which recomputes the signature
     * from $request->url(), not from the URL generator — rejects links that
     * were generated without the port.
     *
     * The forwarded port says nothing about the public origin, so remove it
     * and let the scheme decide the port.
     */
    private function normalizeForwardedPort(Request $request): void
    {
        if (! $request->isFromTrustedProxy() || ! $request->isSecure()) {
            return;
        }

        if ((int) $request->getPort() !== 80) {
            return;
        }

        $request->headers->remove('X-Forwarded-Port');

        // A port appended to a host header would still be resolved as the
        // request port, so strip it there as well.
        foreach (['X-Forwarded-Host', 'HOST'] as $header) {
            if ($host = $request->headers->get($header)) {
                $request->headers->set($header, $this->withoutPort($host));
            }
        }

        $request->server->set('SERVER_PORT', 443);
    }

    /**
     * Remove a trailing ":port" from a host string, keeping IPv6 literals
     * such as "[::1]:80" intact.
     */
    private function withoutPort(string $host): string
    {
        $colon = str_starts_with($host, '[')
            ? strpos($host, ']') === false
                ? false
                : strpos($host, ':', strpos($host, ']'))
            : strrpos($host, ':');

        return $colon === false ? $host : substr($host, 0, $colon);
    }
}
