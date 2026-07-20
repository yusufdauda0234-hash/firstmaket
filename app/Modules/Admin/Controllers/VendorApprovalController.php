<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Vendor\Models\VendorProfile;
use App\Modules\Vendor\Services\VendorApprovalService;
use App\Shared\Enums\VendorStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin vendor approval queue. Reads vendor data for display but delegates
 * every state change to the Vendor module's VendorApprovalService, which
 * owns the transition rules and domain events.
 */
class VendorApprovalController extends Controller
{
    public function index(Request $request): Response
    {
        $status = VendorStatus::tryFrom((string) $request->query('status')) ?? VendorStatus::Pending;

        $vendors = VendorProfile::query()
            ->with('user:id,uuid,name,email,phone,created_at')
            ->where('status', $status)
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (VendorProfile $profile) => [
                'uuid' => $profile->uuid,
                'businessName' => $profile->business_name,
                'contactName' => $profile->contact_name,
                'email' => $profile->user->email,
                'status' => $profile->status->value,
                'registeredAt' => $profile->created_at->toDayDateTimeString(),
            ]);

        return Inertia::render('Admin/Vendors/Index', [
            'vendors' => $vendors,
            'status' => $status->value,
        ]);
    }

    public function show(VendorProfile $vendorProfile): Response
    {
        return Inertia::render('Admin/Vendors/Show', ['vendor' => $this->payload($vendorProfile)]);
    }

    /** JSON detail for the quick-view modal opened from the vendor list. */
    public function details(VendorProfile $vendorProfile): JsonResponse
    {
        return response()->json(['vendor' => $this->payload($vendorProfile)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(VendorProfile $vendorProfile): array
    {
        $vendorProfile->load(['user:id,uuid,name,email,phone,created_at', 'documents', 'approvedBy:id,name']);

        return [
            'uuid' => $vendorProfile->uuid,
            'businessName' => $vendorProfile->business_name,
            'contactName' => $vendorProfile->contact_name,
            'address' => $vendorProfile->address,
            'email' => $vendorProfile->user->email,
            'phone' => $vendorProfile->user->phone,
            'status' => $vendorProfile->status->value,
            'rejectionReason' => $vendorProfile->rejection_reason,
            'approvedBy' => $vendorProfile->approvedBy?->name,
            'approvedAt' => $vendorProfile->approved_at?->toDayDateTimeString(),
            'registeredAt' => $vendorProfile->created_at->toDayDateTimeString(),
            'documents' => $vendorProfile->documents->map(fn ($document) => [
                'uuid' => $document->uuid,
                'type' => $document->document_type->value,
                'originalName' => $document->original_name,
                'uploadedAt' => $document->created_at->toDayDateTimeString(),
            ]),
        ];
    }

    public function approve(Request $request, VendorProfile $vendorProfile, VendorApprovalService $service): RedirectResponse
    {
        $service->approve($vendorProfile, $request->user());

        return redirect()
            ->route('admin.vendors.show', $vendorProfile)
            ->with('status', 'vendor-approved');
    }

    public function reject(Request $request, VendorProfile $vendorProfile, VendorApprovalService $service): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $service->reject($vendorProfile, $request->user(), $validated['reason']);

        return redirect()
            ->route('admin.vendors.show', $vendorProfile)
            ->with('status', 'vendor-rejected');
    }

    public function suspend(Request $request, VendorProfile $vendorProfile, VendorApprovalService $service): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $service->suspend($vendorProfile, $request->user(), $validated['reason']);

        return redirect()
            ->route('admin.vendors.show', $vendorProfile)
            ->with('status', 'vendor-suspended');
    }

    public function reinstate(Request $request, VendorProfile $vendorProfile, VendorApprovalService $service): RedirectResponse
    {
        $service->reinstate($vendorProfile, $request->user());

        return redirect()
            ->route('admin.vendors.show', $vendorProfile)
            ->with('status', 'vendor-reinstated');
    }
}
