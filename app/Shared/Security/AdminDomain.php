<?php

namespace App\Shared\Security;

use Illuminate\Http\Request;

/**
 * Admin, Support, Logistics, and Finance dashboards are served from an
 * isolated subdomain with a scoped session cookie, separate from the
 * customer app's origin (docs/FirstMaket_Security_Compliance.md section 11.1).
 */
final class AdminDomain
{
    public static function matches(Request $request): bool
    {
        // Hostnames are case-insensitive (RFC 3986 §3.2.2); getHost() is
        // already lowercased, so the configured domain must be too.
        return $request->getHost() === strtolower((string) config('app.admin_domain'));
    }
}
