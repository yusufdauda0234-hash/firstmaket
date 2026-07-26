<?php

namespace App\Http\Middleware;

use App\Modules\Catalog\Services\HomeDataService;
use App\Shared\Security\AdminDomain;
use App\Shared\Security\VendorDomain;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        // Header categories are shared so any customer-facing page can render
        // the marketplace PublicLayout without threading categories through
        // every controller. Skipped on the admin/vendor portals, which don't
        // use that layout. Cached in HomeDataService, so this is cheap.
        $isPortal = AdminDomain::matches($request) || VendorDomain::matches($request);

        return [
            ...parent::share($request),
            'categories' => $isPortal ? [] : fn () => app(HomeDataService::class)->categories(),
            'auth' => [
                'user' => $user ? [
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'phoneVerified' => $user->hasVerifiedPhone(),
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // Local/debug only — App\Modules\Identity\Controllers\PhoneVerificationController.
                'devOtpCode' => fn () => $request->session()->get('devOtpCode'),
            ],
            'supportHotline' => config('firstmarket.support.hotline'),
            // Absolute URL of the main marketplace — portal pages (Vendor
            // Center, admin) need it because routes without a domain
            // constraint generate on the current origin.
            'mainSiteUrl' => rtrim(config('app.url'), '/'),
        ];
    }
}
