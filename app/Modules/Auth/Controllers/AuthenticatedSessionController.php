<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Modules\Auth\Requests\LoginRequest;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Security\AdminDomain;
use App\Shared\Security\DeviceFingerprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render(
            AdminDomain::matches($request) ? 'Admin/Auth/Login' : 'Auth/Login'
        );
    }

    public function store(LoginRequest $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        $user = $request->user();
        $fingerprint = DeviceFingerprint::fromRequest($request);

        $isNewDevice = ! LoginEvent::query()
            ->where('user_id', $user->id)
            ->where('device_fingerprint', $fingerprint)
            ->exists();

        LoginEvent::query()->create([
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'device_fingerprint' => $fingerprint,
            'is_new_device' => $isNewDevice,
        ]);

        $user->forceFill(['last_login_at' => now()])->save();

        $auditLogger->log(actor: $user, subject: $user, action: 'auth.login');

        return AdminDomain::matches($request)
            ? redirect()->intended(route('admin.dashboard'))
            : redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $isAdmin = AdminDomain::matches($request);

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect($isAdmin ? route('admin.login') : route('login'));
    }
}
