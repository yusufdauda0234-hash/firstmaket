<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 9 operational controls (docs/FirstMaket_Implementation_Plan.md):
 * user suspension and ban. Session revocation itself needs no code here —
 * EnsureUserIsActive (Sprint 2) already logs out and invalidates the
 * session of any Suspended/Banned user on their very next request, driven
 * purely by the status column this service writes.
 */
class UserModerationService
{
    public function __construct(private readonly AuditLoggerContract $auditLogger) {}

    public function suspend(User $user, User $actor, string $reason): User
    {
        return $this->applyStatus($user, $actor, UserStatus::Suspended, $reason, 'admin.user_suspended');
    }

    public function ban(User $user, User $actor, string $reason): User
    {
        return $this->applyStatus($user, $actor, UserStatus::Banned, $reason, 'admin.user_banned');
    }

    /** Lift a suspension or ban back to Active. */
    public function reactivate(User $user, User $actor): User
    {
        if (! in_array($user->status, [UserStatus::Suspended, UserStatus::Banned], true)) {
            throw ValidationException::withMessages([
                'status' => "Only a suspended or banned user can be reactivated; this user is {$user->status->value}.",
            ]);
        }

        $old = $user->status;

        $user->forceFill([
            'status' => UserStatus::Active,
            'status_reason' => null,
            'status_changed_by' => $actor->id,
            'status_changed_at' => now(),
        ])->save();

        $this->auditLogger->log(
            actor: $actor,
            subject: $user,
            action: 'admin.user_reactivated',
            oldValues: ['status' => $old->value],
            newValues: ['status' => UserStatus::Active->value],
        );

        return $user;
    }

    private function applyStatus(User $user, User $actor, UserStatus $to, string $reason, string $auditAction): User
    {
        if ($user->id === $actor->id) {
            throw ValidationException::withMessages(['user' => 'You cannot moderate your own account.']);
        }

        if ($user->user_type !== UserType::Customer) {
            throw ValidationException::withMessages(['user' => 'Only customer accounts can be moderated from this screen.']);
        }

        if ($user->status === $to) {
            throw ValidationException::withMessages(['status' => "This user is already {$to->value}."]);
        }

        $old = $user->status;

        $user->forceFill([
            'status' => $to,
            'status_reason' => $reason,
            'status_changed_by' => $actor->id,
            'status_changed_at' => now(),
        ])->save();

        $this->auditLogger->log(
            actor: $actor,
            subject: $user,
            action: $auditAction,
            oldValues: ['status' => $old->value],
            newValues: ['status' => $to->value, 'reason' => $reason],
        );

        return $user;
    }
}
