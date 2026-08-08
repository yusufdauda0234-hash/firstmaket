<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Services\UserModerationService;
use App\Modules\Identity\Services\OtpService;
use App\Modules\Orders\Models\Order;
use App\Modules\Savings\Models\Savings;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\OtpChannel;
use App\Shared\Enums\OtpPurpose;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 9 user management (docs/FirstMaket_Implementation_Plan.md):
 * suspend/ban/reactivate customer accounts. Deliberately customer-only —
 * UserModerationService itself also refuses staff accounts as a second,
 * server-side guard. This is a mutation-capable sibling of the read-only
 * Support Agent lookup (CustomerLookupController); the two are kept
 * separate because they're gated by different permissions
 * (customers.suspend vs support.manage).
 */
class UserManagementController extends Controller
{
    /**
     * Create a customer account from the admin side.
     *
     * For someone who ordered over the phone or at a counter and needs an
     * account to track it. No password is set here — staff must never know a
     * customer's credentials — so the account gets an unguessable secret and a
     * link to choose their own.
     */
    public function store(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
        ], [
            'email.unique' => 'An account already uses that email address.',
            'phone.unique' => 'An account already uses that phone number.',
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make(Str::random(48)),
            'user_type' => UserType::Customer,
            'status' => UserStatus::Active,
        ]);

        // email_verified_at is not mass assignable, so it has to be forced —
        // staff creating the account is the verification here.
        $user->forceFill(['email_verified_at' => now()])->save();

        $auditLogger->log(
            actor: $request->user(),
            subject: $user,
            action: 'admin.customer_created',
            newValues: ['email' => $user->email],
        );

        // The app's own code-based reset, not Laravel's link-based one:
        // Password::sendResetLink builds its URL from route('password.reset'),
        // which does not exist here because every reset in this app is a
        // 6-digit code. Calling it threw when the notification was rendered.
        try {
            app(OtpService::class)->request(
                destination: $user->email,
                purpose: OtpPurpose::PasswordReset,
                user: $user,
                requestIp: $request->ip(),
                channel: OtpChannel::Email,
            );
        } catch (\Throwable $e) {
            // The account is already committed; a bounced email must not be
            // reported to staff as a failed creation.
            report($e);
        }

        return back()->with(
            'success',
            "{$user->name} added. We have emailed {$user->email} a 6-digit code to set their password with."
        );
    }

    /**
     * Suspend or reactivate several accounts at once.
     *
     * Ban is deliberately absent: it is the one moderation step with no easy
     * way back, and it deserves the individual screen where the operator can
     * see who they are banning. Each account still goes through
     * UserModerationService, which refuses staff accounts server-side.
     */
    public function bulkUpdate(Request $request, UserModerationService $service): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:suspend,reactivate'],
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid'],
            'reason' => ['required_if:action,suspend', 'nullable', 'string', 'max:500'],
        ], [
            'uuids.required' => 'Select at least one customer first.',
            'reason.required_if' => 'A suspension needs a reason the customer can be told.',
        ]);

        $users = User::query()
            ->where('user_type', UserType::Customer)
            ->whereIn('uuid', $validated['uuids'])
            ->get();

        $done = 0;
        $skipped = 0;

        foreach ($users as $user) {
            try {
                if ($validated['action'] === 'suspend') {
                    $service->suspend($user, $request->user(), (string) $validated['reason']);
                } else {
                    $service->reactivate($user, $request->user());
                }

                $done++;
            } catch (\Throwable) {
                $skipped++;
            }
        }

        $verb = $validated['action'] === 'suspend' ? 'suspended' : 'reactivated';
        $message = "{$done} customer".($done === 1 ? '' : 's')." {$verb}.";

        if ($skipped > 0) {
            $message .= " {$skipped} skipped.";
        }

        return back()->with($done > 0 ? 'success' : 'error', $message);
    }

    public function index(Request $request): Response
    {
        $term = trim((string) $request->query('q', ''));
        $statusFilter = (string) $request->query('status', '');

        $users = User::query()
            ->where('user_type', UserType::Customer)
            ->when($term !== '', fn ($query) => $query->where(function ($query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            }))
            ->when($statusFilter !== '', fn ($query) => $query->where('status', $statusFilter))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user) => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status->value,
                'joinedAt' => $user->created_at->format('j M Y'),
            ]);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'query' => $term,
            'status' => $statusFilter,
        ]);
    }

    public function show(User $user): Response
    {
        abort_unless($user->user_type === UserType::Customer, 404);

        $user->loadMissing('statusChangedBy:id,name');

        return Inertia::render('Admin/Users/Show', [
            'user' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status->value,
                'statusReason' => $user->status_reason,
                'statusChangedBy' => $user->statusChangedBy?->name,
                'statusChangedAt' => $user->status_changed_at?->format('j M Y, g:ia'),
                'joinedAt' => $user->created_at->format('j M Y'),
                'savingsBalanceKobo' => (int) (Savings::query()->where('user_id', $user->id)->value('balance_kobo') ?? 0),
                'orderCount' => Order::query()->where('customer_id', $user->id)->count(),
                'savingsGoalCount' => SavingsGoal::query()->where('user_id', $user->id)->count(),
            ],
        ]);
    }

    public function suspend(Request $request, User $user, UserModerationService $service): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $service->suspend($user, $request->user(), $validated['reason']);

        return redirect()->route('admin.users.show', $user)->with('success', 'Account suspended — sessions end on their next request.');
    }

    public function ban(Request $request, User $user, UserModerationService $service): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $service->ban($user, $request->user(), $validated['reason']);

        return redirect()->route('admin.users.show', $user)->with('success', 'Account banned — sessions end on their next request.');
    }

    public function reactivate(Request $request, User $user, UserModerationService $service): RedirectResponse
    {
        $service->reactivate($user, $request->user());

        return redirect()->route('admin.users.show', $user)->with('success', 'Account reactivated.');
    }
}
