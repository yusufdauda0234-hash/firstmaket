<?php

return [

    'name' => env('APP_NAME', 'FirstMarket'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    // Admin, Support, Logistics, and Finance dashboards are served from this
    // subdomain, isolated from the customer app's origin and session cookie.
    // See docs/firstmarket_Security_Compliance.md section 11.1.
    'admin_domain' => env('ADMIN_DOMAIN', 'admin.localhost'),

    // Vendor Center (dashboard + listing management) lives on its own
    // subdomain with a scoped session cookie, mirroring the admin isolation:
    // customers never see vendor tooling, vendors sign in at their portal.
    'vendor_domain' => env('VENDOR_DOMAIN', 'vendors.localhost'),

    'timezone' => 'Africa/Lagos',

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_NG'),

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    // Framework providers register automatically; app providers live in
    // bootstrap/providers.php. Sanctum, Pennant, Permission, and Inertia all
    // ship Laravel package auto-discovery, so they need no manual entry here.

];
