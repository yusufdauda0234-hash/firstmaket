<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manual phone-number verification queue, used while SMS OTP delivery isn't
 * reliable yet (SmartSMSSolutions transactional/financial route pending —
 * see docs/README SMS provider section). Approve marks the number verified
 * exactly as a correct OTP entry would; reject clears the number so the
 * customer can re-add a phone from Account Settings.
 */
class PhoneReviewController extends Controller
{
    public function index(): Response
    {
        $pending = User::query()
            ->whereNotNull('phone')
            ->whereNull('phone_verified_at')
            ->oldest('id')
            ->paginate(20)
            ->through(fn (User $user) => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'joinedAt' => $user->created_at->toDayDateTimeString(),
            ]);

        return Inertia::render('Admin/Phone/Index', ['users' => $pending]);
    }

    public function approve(Request $request, User $user, AuditLoggerContract $auditLogger): RedirectResponse
    {
        if ($user->phone === null) {
            throw ValidationException::withMessages(['phone' => 'This account has no phone number to verify.']);
        }

        $user->forceFill(['phone_verified_at' => now()])->save();

        $auditLogger->log(
            actor: $request->user(),
            subject: $user,
            action: 'identity.phone_verified_manually',
            newValues: ['phone' => $user->phone],
        );

        return back()->with('success', 'Phone number verified.');
    }

    public function reject(Request $request, User $user, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $oldPhone = $user->phone;

        // No phone-verification-attempt table to record the rejection
        // against, so the number is cleared instead — the customer sees
        // "Add your phone number" again in Account Settings and can retry
        // with a corrected number.
        $user->forceFill(['phone' => null, 'phone_verified_at' => null])->save();

        $auditLogger->log(
            actor: $request->user(),
            subject: $user,
            action: 'identity.phone_rejected_manually',
            oldValues: ['phone' => $oldPhone],
            newValues: ['reason' => $validated['reason']],
        );

        return back()->with('success', 'Phone number rejected and cleared for re-entry.');
    }
}
