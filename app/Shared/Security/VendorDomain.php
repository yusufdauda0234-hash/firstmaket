<?php

namespace App\Shared\Security;

use Illuminate\Http\Request;

/**
 * The Vendor Center (vendor dashboard + listing management) is served from
 * its own subdomain with a scoped session cookie, mirroring the admin
 * portal's isolation from the customer app (see AdminDomain).
 */
final class VendorDomain
{
    public static function matches(Request $request): bool
    {
        // Hostnames are case-insensitive (RFC 3986 §3.2.2); getHost() is
        // already lowercased, so the configured domain must be too.
        return $request->getHost() === strtolower((string) config('app.vendor_domain'));
    }
}
