<?php

namespace App\Shared\Middleware;

use App\Shared\Security\AdminDomain;
use App\Shared\Security\VendorDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer routes carry no domain constraint (they must work on
 * firstmarket.localhost, 127.0.0.1, and the production domain alike), which
 * means they would also answer on the admin and vendor subdomains — e.g.
 * admin.firstmarket.ng/register would happily register a customer. This
 * middleware 404s them there so each portal origin exposes only its own
 * routes.
 */
class NotOnAdminDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        if (AdminDomain::matches($request) || VendorDomain::matches($request)) {
            abort(404);
        }

        return $next($request);
    }
}
