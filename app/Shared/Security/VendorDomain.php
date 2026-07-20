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
        return $request->getHost() === config('app.vendor_domain');
    }
}
