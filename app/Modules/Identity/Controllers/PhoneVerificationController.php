<?php

namespace App\Modules\Identity\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\OtpService;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\OtpPurpose;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Phone verification is optional and self-service (docs/FirstMaket_Implementation_Plan.md
 * Sprint 2 Addendum) — there is no dedicated page or forced redirect. The
 * VerifyPhoneModal on the vendor dashboard posts here and both actions just
 * redirect back to it.
 *
 * The routes live in Modules/Identity/vendor-routes.php, on the Vendor
 * Center subdomain only, because back() has to land on the origin the modal
 * was opened from. Customers verify their phone through the separate
 * account.identifier.* flow on the main site.
 */
class PhoneVerificationController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedPhone()) {
            return back();
        }

        $otp = $this->otp->request($user->phone, OtpPurpose::Registration, $user, $request->ip());

        return back()->with([
            'success' => 'Verification code sent.',
            // Local/debug only — see OtpCode::$plainCode. Never set in
            // production, so the real code never reaches the browser.
            'devOtpCode' => app()->hasDebugModeEnabled() ? $otp->plainCode : null,
        ]);
    }

    public function verify(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'digits:6']]);

        $user = $request->user();

        $this->otp->verify($user->phone, OtpPurpose::Registration, $validated['code']);

        $user->forceFill(['phone_verified_at' => now()])->save();

        $auditLogger->log(actor: $user, subject: $user, action: 'identity.phone_verified');

        return back()->with('success', 'Phone number verified.');
    }
}
