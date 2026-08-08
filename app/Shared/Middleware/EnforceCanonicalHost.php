<?php

namespace App\Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nothing stops a browser from reaching the app on the wrong hostname: the dev
 * server (like most webservers) answers whatever Host header it is handed, and
 * browsers resolve every *.localhost name to the loopback address (RFC 6761).
 * A typo such as "firstmarket.localhost" therefore serves the entire site, and
 * because links are relative the visitor stays stuck on it.
 *
 * That is worse than a cosmetically wrong URL. Session cookies are scoped per
 * host, so the mistyped origin carries its own empty session — the visitor
 * looks logged out with an empty cart — and the admin/vendor route groups stop
 * matching their domain constraint, so those portals 404.
 *
 * Redirecting unknown hostnames to their canonical equivalent makes stale
 * bookmarks and mistyped URLs self-healing.
 */
class EnforceCanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        // Safe methods only. A redirect replays a POST as a GET, which would
        // silently drop submitted form data and break signed webhooks.
        if (! $request->isMethodSafe()) {
            return $next($request);
        }

        // Uptime probes send whatever Host header their monitor is configured
        // with; a 302 would read as "unhealthy".
        if ($request->is('up')) {
            return $next($request);
        }

        $host = $request->getHost();

        // Bare "localhost" and raw IPs are legitimate ways to reach the app —
        // the customer routes carry no domain constraint precisely so they
        // keep working on 127.0.0.1.
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return $next($request);
        }

        $canonical = $this->canonicalHostFor($host);

        if ($canonical === null) {
            return $next($request);
        }

        return redirect()->away($this->rebuild($request, $canonical));
    }

    /**
     * The host this request should have been made to, or null to leave it be.
     */
    private function canonicalHostFor(string $host): ?string
    {
        $app = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $admin = strtolower((string) config('app.admin_domain'));
        $vendor = strtolower((string) config('app.vendor_domain'));

        // Without a configured canonical host there is nothing to point at.
        if ($app === '' || $app === 'localhost') {
            return null;
        }

        if (in_array($host, [$app, $admin, $vendor], true)) {
            return null;
        }

        // Match on the leading label so admin.firstmarket.localhost lands on
        // the admin portal rather than the storefront.
        $label = explode('.', $host)[0];

        return match ($label) {
            explode('.', $admin)[0] => $admin,
            explode('.', $vendor)[0] => $vendor,
            default => $app,
        };
    }

    /**
     * Same scheme, path and query string — only the host swapped. The port is
     * kept because local development runs on :8000.
     */
    private function rebuild(Request $request, string $host): string
    {
        $scheme = $request->getScheme();
        $port = $request->getPort();
        $isDefaultPort = $port === ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.$host.($isDefaultPort ? '' : ':'.$port).$request->getRequestUri();
    }
}
