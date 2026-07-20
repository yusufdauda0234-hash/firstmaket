<?php

namespace App\Modules\Vendor\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vendor Center dashboard on the vendors subdomain. The portal middleware
 * (EnsureCorrectPortal) guarantees only vendor accounts reach this point.
 */
class VendorDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $profile = $request->user()->vendorProfile;

        $counts = $profile === null
            ? collect()
            : Product::query()
                ->where('vendor_id', $profile->id)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

        return Inertia::render('Vendor/Dashboard', [
            'businessName' => $profile?->business_name,
            'vendorStatus' => $profile?->status->value,
            'rejectionReason' => $profile?->rejection_reason,
            'productCounts' => [
                'draft' => (int) ($counts['draft'] ?? 0),
                'pendingApproval' => (int) ($counts['pending_approval'] ?? 0),
                'approved' => (int) ($counts['approved'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
                'delisted' => (int) ($counts['delisted'] ?? 0),
            ],
        ]);
    }
}
