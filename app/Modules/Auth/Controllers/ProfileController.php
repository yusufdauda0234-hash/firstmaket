<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function customer(Request $request): Response
    {
        return Inertia::render('Account/Profile', [
            'account' => $this->accountData($request),
        ]);
    }

    public function vendor(Request $request): Response
    {
        $user = $request->user()->load('vendorProfile');

        return Inertia::render('Vendor/Profile', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'emailVerified' => $user->email_verified_at !== null,
                'phoneVerified' => $user->phone_verified_at !== null,
                'businessName' => $user->vendorProfile?->business_name,
                'contactName' => $user->vendorProfile?->contact_name,
                'address' => $user->vendorProfile?->address,
                'status' => $user->vendorProfile?->status->value,
            ],
        ]);
    }

    public function staff(Request $request): Response
    {
        $user = $request->user()->load('roles');

        return Inertia::render('Admin/Profile', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'emailVerified' => $user->email_verified_at !== null,
                'phoneVerified' => $user->phone_verified_at !== null,
                'roles' => $user->roles->pluck('name')->values(),
            ],
        ]);
    }

    public function updateCustomer(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $this->updateName($request, $auditLogger, 'account.profile_updated');

        return back()->with('success', 'Profile updated.');
    }

    public function updateVendor(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'business_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $before = ['name' => $user->name, 'vendor_profile' => $user->vendorProfile?->only(['business_name', 'contact_name', 'address'])];
        $user->forceFill(['name' => $data['name']])->save();
        $user->vendorProfile?->update([
            'business_name' => $data['business_name'],
            'contact_name' => $data['contact_name'],
            'address' => $data['address'] ?? null,
        ]);

        $auditLogger->log(actor: $user, subject: $user, action: 'account.profile_updated', oldValues: $before);

        return back()->with('success', 'Profile updated.');
    }

    public function updateStaff(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $this->updateName($request, $auditLogger, 'staff.profile_updated');

        return back()->with('success', 'Profile updated.');
    }

    public function updatePassword(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();
        $user->forceFill(['password' => Hash::make($request->string('password')->value())])->save();
        $auditLogger->log(actor: $user, subject: $user, action: 'account.password_changed');

        return back()->with('success', 'Password updated.');
    }

    private function accountData(Request $request): array
    {
        $user = $request->user();

        return [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'emailVerified' => $user->email_verified_at !== null,
            'phoneVerified' => $user->phone_verified_at !== null,
            'hasPassword' => $user->password !== null,
        ];
    }

    private function updateName(Request $request, AuditLoggerContract $auditLogger, string $action): void
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $user = $request->user();
        $oldName = $user->name;
        $user->forceFill(['name' => $data['name']])->save();

        $auditLogger->log(actor: $user, subject: $user, action: $action, oldValues: ['name' => $oldName]);
    }
}