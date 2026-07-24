<?php

namespace App\Shared\Middleware;

use App\Shared\Security\AdminDomain;
use App\Shared\Security\VendorDomain;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before Laravel's StartSession middleware (prepended to the "web"
 * group in bootstrap/app.php) so requests to the admin and vendor
 * subdomains each get their own session cookie name and domain instead of
 * the customer app's. This is what keeps an XSS on the public/customer
 * surface from being able to reach an admin or vendor session cookie
 * (docs/FirstMaket_Security_Compliance.md section 11.1).
 */
class ScopeAdminSessionCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        if (AdminDomain::matches($request)) {
            Config::set('session.cookie', Config::get('session.cookie').'_admin');
            Config::set('session.domain', $request->getHost());
        } elseif (VendorDomain::matches($request)) {
            Config::set('session.cookie', Config::get('session.cookie').'_vendor');
            Config::set('session.domain', $request->getHost());
        }

        return $next($request);
    }
}
