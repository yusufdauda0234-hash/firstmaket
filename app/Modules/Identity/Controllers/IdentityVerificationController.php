<?php

namespace App\Modules\Identity\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\IdentityVerification;
use App\Modules\Identity\Services\IdentityVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer-facing identity verification status screen plus BVN/NIN
 * submission. Verification attempts and outcomes are reviewable by the user
 * here and by staff through audit logs
 * (docs/firstmarket_Implementation_Plan.md Sprint 2 exit criteria).
 */
class IdentityVerificationController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Identity/Status', [
            'emailVerified' => $user->hasVerifiedEmail(),
            'phoneVerified' => $user->hasVerifiedPhone(),
            'identityStatus' => $user->customerProfile?->identity_status->value
                ?? $user->vendorProfile?->status->value
                ?? 'unverified',
            'verifications' => IdentityVerification::query()
                ->where('user_id', $user->id)
                ->latest('id')
                ->get()
                ->map(fn (IdentityVerification $verification) => [
                    'type' => $verification->type->value,
                    'status' => $verification->status->value,
                    'failureReason' => $verification->failure_reason,
                    'submittedAt' => $verification->created_at->toDayDateTimeString(),
                ]),
        ]);
    }

    public function storeBvn(Request $request, IdentityVerificationService $service): RedirectResponse
    {
        $validated = $request->validate(['bvn' => ['required', 'digits:11']]);

        $service->verifyBvn($request->user(), $validated['bvn']);

        return redirect()->route('identity.status');
    }

    public function storeNin(Request $request, IdentityVerificationService $service): RedirectResponse
    {
        $validated = $request->validate(['nin' => ['required', 'digits:11']]);

        $service->verifyNin($request->user(), $validated['nin']);

        return redirect()->route('identity.status');
    }
}
