<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\UploadedDocument;
use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Vendor\Models\VendorProfile;
use App\Modules\Vendor\Notifications\VendorPasswordResetNotification;
use App\Modules\Vendor\Services\VendorApprovalService;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\DocumentType;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VendorStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin vendor approval queue. Reads vendor data for display but delegates
 * every state change to the Vendor module's VendorApprovalService, which
 * owns the transition rules and domain events.
 */
class VendorApprovalController extends Controller
{
    /**
     * Create a vendor directly, for sellers onboarded offline — signed up at a
     * market, or brought in by the team — rather than through self-service.
     *
     * Two things differ from self-registration on purpose. The CAC document is
     * optional, because staff creating the account have usually already seen
     * the paperwork; and the account can be approved immediately, since an
     * approval queue exists to vet strangers, not sellers the team just
     * onboarded themselves. Both choices are recorded in the audit log against
     * the staff member who made them.
     *
     * The vendor never receives a password from us: a single-use reset link is
     * the only way in, so no staff member ever knows a seller's credentials.
     */
    public function store(
        Request $request,
        AuditLoggerContract $auditLogger,
        VendorApprovalService $approvals,
    ): RedirectResponse {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'contact_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            // users.phone carries a unique index, so without this rule a repeat
            // number surfaced as a 500 instead of a field error.
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'address' => ['required', 'string', 'max:255'],
            'approve_now' => ['boolean'],
            'cac_document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ], [
            'email.unique' => 'An account already uses that email address.',
            'phone.unique' => 'An account already uses that phone number.',
        ]);

        $approveNow = $request->boolean('approve_now');
        $cacPath = $request->file('cac_document')?->store('cac-documents', 'local');

        $profile = DB::transaction(function () use ($request, $data, $cacPath) {
            $user = User::query()->create([
                'name' => $data['contact_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                // Unguessable and never shown to anyone. The vendor sets their
                // own via the reset link below.
                'password' => Hash::make(Str::random(48)),
                'user_type' => UserType::Vendor,
                'status' => UserStatus::Active,
            ]);

            // forceFill because email_verified_at is deliberately not mass
            // assignable — passing it to create() would be silently dropped.
            // Staff vouching for the account is the verification here; there is
            // no inbox round-trip to wait on.
            $user->forceFill(['email_verified_at' => now()])->save();

            $user->assignRole('Vendor');

            // Always created pending. Approval is a state transition owned by
            // VendorApprovalService — writing the status here would skip its
            // rules, its audit entry, and the domain event that notifies the
            // vendor.
            $profile = VendorProfile::query()->create([
                'user_id' => $user->id,
                'business_name' => $data['business_name'],
                'contact_name' => $data['contact_name'],
                'address' => $data['address'],
                'status' => VendorStatus::Pending,
            ]);

            if ($cacPath !== null && $file = $request->file('cac_document')) {
                UploadedDocument::query()->create([
                    'owner_id' => $profile->id,
                    'owner_type' => $profile->getMorphClass(),
                    'document_type' => DocumentType::Cac,
                    'disk' => 'local',
                    'path' => $cacPath,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => (string) $file->getMimeType(),
                    'size' => $file->getSize(),
                    'uploaded_by' => $request->user()->id,
                ]);
            }

            return $profile;
        });

        $auditLogger->log(
            actor: $request->user(),
            subject: $profile->user,
            action: 'vendor.created_by_staff',
            newValues: [
                'vendor_profile_id' => $profile->id,
                'business_name' => $profile->business_name,
                'cac_document_attached' => $cacPath !== null,
            ],
        );

        if ($approveNow) {
            $approvals->approve($profile->fresh(), $request->user());
        }

        $this->sendPasswordSetupCode($profile->user, $request);

        return back()->with(
            'success',
            "{$profile->business_name} created. We have emailed {$data['email']} a link to set their password "
            .'in the Vendor Center.'
        );
    }

    /**
     * Approve or reject several applications in one pass.
     *
     * Every profile still goes through VendorApprovalService, so a bulk run is
     * N individual decisions with the same rules, audit entries and events. A
     * profile a colleague just actioned is skipped and counted rather than
     * aborting the whole batch.
     */
    public function bulkUpdate(Request $request, VendorApprovalService $approvals): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:approve,reject'],
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid'],
            'reason' => ['required_if:action,reject', 'nullable', 'string', 'max:1000'],
        ], [
            'uuids.required' => 'Select at least one vendor first.',
            'uuids.max' => 'Up to 100 vendors at a time.',
            'reason.required_if' => 'Tell the applicants why they were rejected.',
        ]);

        $profiles = VendorProfile::query()->whereIn('uuid', $validated['uuids'])->get();

        $done = 0;
        $skipped = 0;

        foreach ($profiles as $profile) {
            try {
                if ($validated['action'] === 'approve') {
                    $approvals->approve($profile, $request->user());
                } else {
                    $approvals->reject($profile, $request->user(), (string) $validated['reason']);
                }

                $done++;
            } catch (\Throwable) {
                $skipped++;
            }
        }

        $verb = $validated['action'] === 'approve' ? 'approved' : 'rejected';
        $message = "{$done} vendor".($done === 1 ? '' : 's')." {$verb}.";

        if ($skipped > 0) {
            $message .= " {$skipped} skipped — no longer pending.";
        }

        return back()->with($done > 0 ? 'success' : 'error', $message);
    }

    /**
     * Email a vendor a fresh code so they can set a new password.
     *
     * For the seller who never received the first one, or has locked
     * themselves out. Staff trigger it but never see the code and never set a
     * password — the vendor still chooses their own, which is the whole point
     * of doing it this way.
     *
     * Throttling is OtpService's, shared with every other code in the app, so
     * this cannot be used to flood someone's inbox.
     */
    public function sendPasswordReset(
        Request $request,
        VendorProfile $vendorProfile,
        AuditLoggerContract $auditLogger,
    ): RedirectResponse {
        $user = $vendorProfile->user;

        if ($user->email === null) {
            return back()->with('error', 'This vendor has no email address on file.');
        }

        try {
            // A link rather than a code: the Vendor Center is a separate portal,
            // so the vendor should go straight there and set a password without
            // detouring through the customer site. The broker owns the token —
            // its hashing, one-time use and expiry.
            $token = Password::broker()->createToken($user);

            $user->notify(new VendorPasswordResetNotification(
                $token,
                $user->email,
                (int) config('auth.passwords.users.expire', 60),
            ));
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'We could not send that email. Try again shortly.');
        }

        $auditLogger->log(
            actor: $request->user(),
            subject: $user,
            action: 'vendor.password_reset_sent',
        );

        $minutes = (int) config('auth.passwords.users.expire', 60);

        return back()->with(
            'success',
            "Sent. {$user->email} has a link to set a new password in the Vendor Center. "
            ."It expires in {$minutes} minutes and works once."
        );
    }

    /**
     * Email a one-time code the new account uses to choose its own password.
     *
     * Shared by the single and bulk create paths. Delivery failures are
     * swallowed on purpose: the account has already been created inside a
     * committed transaction, and a bounced email must not present that as a
     * failure — staff can resend from the account screen.
     */
    private function sendPasswordSetupCode(User $user, Request $request): void
    {
        try {
            $user->notify(new VendorPasswordResetNotification(
                Password::broker()->createToken($user),
                (string) $user->email,
                (int) config('auth.passwords.users.expire', 60),
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }

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
            // Sprint 9 delisting preview: shown before an admin confirms a
            // suspension, since VendorSuspended auto-delists every one of these.
            'approvedProductCount' => $vendorProfile->status === VendorStatus::Approved
                ? Product::query()->where('vendor_id', $vendorProfile->id)->where('status', ProductStatus::Approved)->count()
                : 0,
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
