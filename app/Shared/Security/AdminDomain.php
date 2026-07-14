<?php

namespace App\Shared\Security;

use Illuminate\Http\Request;

/**
 * Admin, Support, Logistics, and Finance dashboards are served from an
 * isolated subdomain with a scoped session cookie, separate from the
 * customer app's origin (docs/firstmarket_Security_Compliance.md section 11.1).
 */
final class AdminDomain
{
    public static function matches(Request $request): bool
    {
        return $request->getHost() === config('app.admin_domain');
    }
}
