<?php

namespace App\Modules\Identity\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\OtpCode;
use App\Modules\Identity\Services\OtpService;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\OtpPurpose;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PhoneVerificationController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * OTP entry screen. Sends a fresh code on first visit so the user
     * arriving from registration already has one in their inbox; refreshing
     * the page does not resend while a code is still active.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedPhone()) {
            return redirect()->route('dashboard');
        }

        $codeSendFailed = false;

        if (! $this->hasActiveCode($user->phone)) {
            try {
                $this->otp->request($user->phone, OtpPurpose::Registration, $user, $request->ip());
            } catch (ValidationException) {
                $codeSendFailed = true;
            }
        }

        return Inertia::render('Auth/VerifyPhone', [
            'phone' => $user->phone,
            'codeSendFailed' => $codeSendFailed,
        ]);
    }

    public function verify(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'digits:6']]);

        $user = $request->user();

        $this->otp->verify($user->phone, OtpPurpose::Registration, $validated['code']);

        $user->forceFill(['phone_verified_at' => now()])->save();

        $auditLogger->log(actor: $user, subject: $user, action: 'identity.phone_verified');

        return redirect()->route('dashboard')->with('status', 'phone-verified');
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasVerifiedPhone()) {
            $this->otp->request($user->phone, OtpPurpose::Registration, $user, $request->ip());
        }

        return back()->with('status', 'otp-sent');
    }

    private function hasActiveCode(string $phone): bool
    {
        return OtpCode::query()
            ->where('destination', $phone)
            ->where('purpose', OtpPurpose::Registration)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->exists();
    }
}
