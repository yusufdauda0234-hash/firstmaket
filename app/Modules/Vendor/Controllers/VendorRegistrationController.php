<?php

namespace App\Modules\Vendor\Controllers;

use App\Http\Controllers\Controller;
use App\Models\UploadedDocument;
use App\Models\User;
use App\Modules\Vendor\Models\VendorProfile;
use App\Modules\Vendor\Requests\RegisterVendorRequest;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\DocumentType;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VendorStatus;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Vendor onboarding: account + vendor profile + CAC document in one step.
 * The profile starts Pending and product listing stays locked until an
 * Administrator approves it (docs/FirstMaket_Implementation_Plan.md Sprint 2).
 * The CAC file goes to the private local disk — never a public one.
 */
class VendorRegistrationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/RegisterVendor');
    }

    public function store(RegisterVendorRequest $request, AuditLoggerContract $auditLogger): SymfonyResponse
    {
        $cacPath = $request->file('cac_document')->store('cac-documents', 'local');

        $user = DB::transaction(function () use ($request, $cacPath) {
            $user = User::query()->create([
                'name' => $request->string('contact_name'),
                'email' => $request->string('email'),
                'phone' => $request->string('phone'),
                'password' => Hash::make($request->string('password')->value()),
                'user_type' => UserType::Vendor,
                'status' => UserStatus::Active,
            ]);

            $user->assignRole('Vendor');

            $profile = VendorProfile::query()->create([
                'user_id' => $user->id,
                'business_name' => $request->string('business_name'),
                'contact_name' => $request->string('contact_name'),
                'address' => $request->string('address'),
                'status' => VendorStatus::Pending,
            ]);

            UploadedDocument::query()->create([
                'owner_id' => $profile->id,
                'owner_type' => $profile->getMorphClass(),
                'document_type' => DocumentType::Cac,
                'disk' => 'local',
                'path' => $cacPath,
                'original_name' => $request->file('cac_document')->getClientOriginalName(),
                'mime_type' => (string) $request->file('cac_document')->getMimeType(),
                'size' => $request->file('cac_document')->getSize(),
                'uploaded_by' => $user->id,
            ]);

            return $user;
        });

        event(new Registered($user));

        $auditLogger->log(actor: $user, subject: $user, action: 'vendor.register');

        Auth::login($user);

        // The Vendor Center lives on its own subdomain with a scoped session
        // cookie (App\Shared\Security\VendorDomain) — this main-site session
        // does not carry over, so send them to sign in there rather than
        // straight to vendor.dashboard. It has to be an Inertia::location
        // (409 + X-Inertia-Location) and not a plain redirect: the form posts
        // over XHR, and the browser would follow a 302 cross-origin and then
        // block the response for want of CORS headers, leaving the page stuck.
        return Inertia::location(route('vendor.login', ['registered' => 1]));
    }
}
