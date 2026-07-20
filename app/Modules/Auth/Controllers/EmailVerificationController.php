<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): Response|RedirectResponse
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect()->route('dashboard')
            : Inertia::render('Auth/VerifyEmail', [
                'status' => $request->session()->get('status'),
            ]);
    }

    public function verify(EmailVerificationRequest $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->fulfill();

            $auditLogger->log(
                actor: $request->user(),
                subject: $request->user(),
                action: 'identity.email_verified',
            );
        }

        return redirect()->route('dashboard')->with('status', 'email-verified');
    }

    public function send(Request $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('status', 'verification-link-sent');
    }
}
