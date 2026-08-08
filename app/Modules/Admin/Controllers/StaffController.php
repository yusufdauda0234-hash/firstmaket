<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Logistics\Models\CourierProfile;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\OtpChannel;
use App\Shared\Enums\OtpPurpose;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VehicleType;
use App\Shared\Nigeria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Staff accounts — couriers, support agents, finance officers.
 *
 * Until now there was no way to make one at all: every staff account in the
 * system came from a seeder or a tinker session, which is fine for a
 * developer and impossible for whoever actually runs the business. Hiring a
 * courier should not need a deploy.
 *
 * Behind `staff.manage`, held only by Administrator and above, because
 * creating a staff account is creating a way into the admin domain.
 *
 * Nobody sets anybody else's password. The account gets an unguessable
 * secret and the new staff member is emailed a code to choose their own —
 * the same rule that governs customer creation, and for the same reason.
 */
class StaffController extends Controller
{
    /**
     * Roles that may be handed out here.
     *
     * Super Administrator is deliberately absent: granting the role that
     * bypasses every permission check is not an ordinary staffing decision,
     * and it stays a deployment-time act.
     */
    private const ASSIGNABLE_ROLES = [
        'Administrator',
        'Logistics Personnel',
        'Support Agent',
        'Finance Officer',
    ];

    public function index(Request $request): Response
    {
        $term = trim((string) $request->query('q', ''));
        $roleFilter = (string) $request->query('role', '');

        $staff = User::query()
            ->where('user_type', UserType::Staff)
            ->with(['roles:id,name', 'courierProfile'])
            ->when($term !== '', fn ($query) => $query->where(function ($query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            }))
            ->when($roleFilter !== '', fn ($query) => $query->whereHas(
                'roles',
                fn ($role) => $role->where('name', $roleFilter),
            ))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user) => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'roles' => $user->roles->pluck('name'),
                'status' => $user->status->value,
                'isCourier' => $user->hasRole('Logistics Personnel'),
                'courier' => $user->courierProfile === null ? null : [
                    'vehicleType' => $user->courierProfile->vehicle_type->value,
                    'vehicleLabel' => $user->courierProfile->vehicle_type->label(),
                    'vehiclePlate' => $user->courierProfile->vehicle_plate,
                    'baseState' => $user->courierProfile->base_state,
                    'baseLga' => $user->courierProfile->base_lga,
                    'maxOpenShipments' => $user->courierProfile->max_open_shipments,
                    'isAvailable' => $user->courierProfile->is_available,
                    'openCount' => $user->courierProfile->openShipmentCount(),
                ],
                'joinedAt' => $user->created_at->format('j M Y'),
            ]);

        return Inertia::render('Admin/Staff/Index', [
            'staff' => $staff,
            'roles' => self::ASSIGNABLE_ROLES,
            'vehicleTypes' => array_map(fn (VehicleType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'hint' => $type->capacityHint(),
            ], VehicleType::cases()),
            'states' => Nigeria::STATES,
            'query' => $term,
            'role' => $roleFilter,
        ]);
    }

    public function store(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $data = $this->validated($request);

        $user = DB::transaction(function () use ($data) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                // Unguessable and never shown. Staff choose their own via the
                // emailed code; nobody here ever knows it.
                'password' => Hash::make(Str::random(48)),
                'user_type' => UserType::Staff,
                'status' => UserStatus::Active,
            ]);

            // Not mass assignable — an admin creating the account is the
            // verification, the same as for a counter-created customer.
            $user->forceFill(['email_verified_at' => now()])->save();

            $user->syncRoles([$data['role']]);

            if ($data['role'] === 'Logistics Personnel') {
                CourierProfile::query()->create([
                    'user_id' => $user->id,
                    'vehicle_type' => $data['vehicle_type'],
                    'vehicle_plate' => $data['vehicle_plate'] ?? null,
                    'base_state' => $data['base_state'] ?? null,
                    'base_lga' => $data['base_lga'] ?? null,
                    'max_open_shipments' => $data['max_open_shipments'] ?? 0,
                    'is_available' => true,
                ]);
            }

            return $user;
        });

        $auditLogger->log(
            actor: $request->user(),
            subject: $user,
            action: 'admin.staff_created',
            newValues: ['email' => $user->email, 'role' => $data['role']],
        );

        $sent = $this->sendPasswordCode($request, $user);

        // The account exists either way. Which of the two happened decides
        // what the admin has to do next, so it decides what they are told.
        return $sent
            ? back()->with(
                'success',
                "{$user->name} added as {$data['role']}. We have emailed {$user->email} a 6-digit code to set their password with."
            )
            : back()->with(
                'error',
                "{$user->name} was added, but the email could not be sent. Check the mail settings, then use the key icon on their row to send the code again."
            );
    }

    /** Change a staff member's role, or a courier's vehicle and patch. */
    public function update(Request $request, User $user, AuditLoggerContract $auditLogger): RedirectResponse
    {
        abort_unless($user->user_type === UserType::Staff, 404);

        $data = $this->validated($request, $user);
        $before = ['role' => $user->roles->pluck('name')->first()];

        DB::transaction(function () use ($user, $data) {
            $user->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
            ]);

            $user->syncRoles([$data['role']]);

            if ($data['role'] === 'Logistics Personnel') {
                CourierProfile::query()->updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'vehicle_type' => $data['vehicle_type'],
                        'vehicle_plate' => $data['vehicle_plate'] ?? null,
                        'base_state' => $data['base_state'] ?? null,
                        'base_lga' => $data['base_lga'] ?? null,
                        'max_open_shipments' => $data['max_open_shipments'] ?? 0,
                        'is_available' => (bool) ($data['is_available'] ?? true),
                    ],
                );
            }
        });

        $auditLogger->log(
            actor: $request->user(),
            subject: $user,
            action: 'admin.staff_updated',
            oldValues: $before,
            newValues: ['role' => $data['role']],
        );

        return back()->with('success', "{$user->name} updated.");
    }

    /**
     * Suspend a staff account.
     *
     * Not a delete: the audit trail, the deliveries they carried and the
     * orders they confirmed all point at this user, and removing the row
     * would orphan the record of who did what.
     *
     * A courier is taken off the dispatch list at the same time, otherwise a
     * suspended account keeps being offered parcels.
     */
    public function suspend(Request $request, User $user, AuditLoggerContract $auditLogger): RedirectResponse
    {
        abort_unless($user->user_type === UserType::Staff, 404);

        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'staff' => 'You cannot suspend your own account.',
            ]);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        DB::transaction(function () use ($user, $request, $validated) {
            $user->forceFill([
                'status' => UserStatus::Suspended,
                'status_reason' => $validated['reason'] ?? null,
                'status_changed_by' => $request->user()->id,
                'status_changed_at' => now(),
            ])->save();

            $user->courierProfile?->forceFill(['is_available' => false])->save();
        });

        $auditLogger->log(actor: $request->user(), subject: $user, action: 'admin.staff_suspended');

        return back()->with('success', "{$user->name} suspended. They can no longer sign in.");
    }

    public function reactivate(Request $request, User $user, AuditLoggerContract $auditLogger): RedirectResponse
    {
        abort_unless($user->user_type === UserType::Staff, 404);

        DB::transaction(function () use ($user, $request) {
            $user->forceFill([
                'status' => UserStatus::Active,
                'status_reason' => null,
                'status_changed_by' => $request->user()->id,
                'status_changed_at' => now(),
            ])->save();

            $user->courierProfile?->forceFill(['is_available' => true])->save();
        });

        $auditLogger->log(actor: $request->user(), subject: $user, action: 'admin.staff_reactivated');

        return back()->with('success', "{$user->name} reactivated.");
    }

    /**
     * Take a courier off the dispatch list without suspending them.
     *
     * The everyday case: on leave, off sick, or already loaded. Distinct from
     * suspension, which is about trust — conflating the two would mean a
     * courier's day off looked like a disciplinary record.
     */
    public function toggleAvailability(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->user_type === UserType::Staff, 404);

        $profile = $user->courierProfile;

        abort_if($profile === null, 404, 'That staff member is not a courier.');

        $profile->forceFill(['is_available' => ! $profile->is_available])->save();

        return back()->with(
            'success',
            $profile->is_available
                ? "{$user->name} is back on the dispatch list."
                : "{$user->name} is off the dispatch list. Parcels already with them are unchanged.",
        );
    }

    /** Re-send the code a staff member sets their password with. */
    public function resendPasswordCode(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->user_type === UserType::Staff, 404);

        return $this->sendPasswordCode($request, $user)
            ? back()->with('success', "New code sent to {$user->email}.")
            : back()->with('error', "Could not send to {$user->email}. Check the mail settings and try again.");
    }

    /**
     * The app's own code-based reset, not Laravel's link-based one.
     *
     * Password::sendResetLink builds its URL from route('password.reset'),
     * which does not exist here because every reset in this app is a 6-digit
     * code.
     */
    private function sendPasswordCode(Request $request, User $user): bool
    {
        try {
            app(OtpService::class)->request(
                destination: $user->email,
                purpose: OtpPurpose::PasswordReset,
                user: $user,
                requestIp: $request->ip(),
                channel: OtpChannel::Email,
            );

            return true;
        } catch (\Throwable $e) {
            // The account is already committed, so a mail failure must not be
            // reported as a failed creation — but it must not be reported as
            // a success either. This swallowed the error and still told the
            // admin "we have emailed them a code" while nothing was sent, so
            // the caller now gets the answer and says which happened.
            report($e);

            return false;
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?User $existing = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($existing?->id),
            ],
            'phone' => [
                'nullable', 'string', 'max:20',
                'regex:/^(\+?234|0)[789][01]\d{8}$/',
                Rule::unique('users', 'phone')->ignore($existing?->id),
            ],
            'role' => ['required', Rule::in(self::ASSIGNABLE_ROLES)],
            'vehicle_type' => ['nullable', Rule::enum(VehicleType::class)],
            'vehicle_plate' => ['nullable', 'string', 'max:20'],
            'base_state' => ['nullable', 'string', Rule::in(Nigeria::STATES)],
            'base_lga' => ['nullable', 'string', 'max:80'],
            'max_open_shipments' => ['nullable', 'integer', 'min:0', 'max:500'],
            'is_available' => ['boolean'],
        ], [
            'email.unique' => 'An account already uses that email address.',
            'phone.unique' => 'An account already uses that phone number.',
            'phone.regex' => 'Enter a valid Nigerian phone number, e.g. 08031234567.',
        ]);

        // A role that must exist for syncRoles to mean anything. Guards the
        // case where the seeder has not been run on a fresh environment.
        if (! Role::query()->where('name', $validated['role'])->exists()) {
            throw ValidationException::withMessages([
                'role' => 'That role does not exist yet. Run the roles seeder.',
            ]);
        }

        if ($validated['role'] === 'Logistics Personnel') {
            // A courier reachable only by email is no use at a locked gate.
            if (($validated['phone'] ?? null) === null) {
                throw ValidationException::withMessages([
                    'phone' => 'A courier needs a phone number — customers and the dispatch desk both call it.',
                ]);
            }

            $validated['vehicle_type'] ??= VehicleType::Motorcycle->value;
        }

        return $validated;
    }
}
