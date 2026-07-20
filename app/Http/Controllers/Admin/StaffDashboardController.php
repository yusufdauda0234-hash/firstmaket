<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\VendorStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StaffDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return match (true) {
            $user->hasAnyRole(['Super Administrator', 'Administrator']) => Inertia::render('Admin/Dashboard', [
                'pendingVendors' => VendorProfile::query()->where('status', VendorStatus::Pending)->count(),
                'pendingProducts' => Product::query()->where('status', ProductStatus::PendingApproval)->count(),
            ]),
            $user->hasRole('Finance Officer') => Inertia::render('Finance/Dashboard'),
            $user->hasRole('Support Agent') => Inertia::render('Support/Dashboard'),
            $user->hasRole('Logistics Personnel') => Inertia::render('Logistics/Dashboard'),
            default => throw new HttpException(403, 'This account has no staff dashboard access.'),
        };
    }
}
