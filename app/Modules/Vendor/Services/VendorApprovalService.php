<?php

namespace App\Modules\Vendor\Services;

use App\Models\User;
use App\Modules\Vendor\Events\VendorApproved;
use App\Modules\Vendor\Events\VendorRejected;
use App\Modules\Vendor\Events\VendorSuspended;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\VendorStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Vendor module owns vendor state transitions; the Admin module's
 * approval screens call in through this service rather than mutating
 * VendorProfile rows themselves, and downstream modules react to the
 * emitted domain events.
 */
class VendorApprovalService
{
    public function __construct(private readonly AuditLoggerContract $auditLogger) {}

    public function approve(VendorProfile $profile, User $approver): VendorProfile
    {
        $this->assertPending($profile);

        DB::transaction(function () use ($profile, $approver) {
            $oldStatus = $profile->status;

            $profile->forceFill([
                'status' => VendorStatus::Approved,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'rejection_reason' => null,
            ])->save();

            $this->auditLogger->log(
                actor: $approver,
                subject: $profile,
                action: 'vendor.approved',
                oldValues: ['status' => $oldStatus->value],
                newValues: ['status' => VendorStatus::Approved->value],
            );
        });

        VendorApproved::dispatch($profile->id, $profile->user_id, $approver->id);

        return $profile;
    }

    public function reject(VendorProfile $profile, User $rejector, string $reason): VendorProfile
    {
        $this->assertPending($profile);

        DB::transaction(function () use ($profile, $rejector, $reason) {
            $oldStatus = $profile->status;

            $profile->forceFill([
                'status' => VendorStatus::Rejected,
                'rejection_reason' => $reason,
            ])->save();

            $this->auditLogger->log(
                actor: $rejector,
                subject: $profile,
                action: 'vendor.rejected',
                oldValues: ['status' => $oldStatus->value],
                newValues: ['status' => VendorStatus::Rejected->value, 'reason' => $reason],
            );
        });

        VendorRejected::dispatch($profile->id, $profile->user_id, $rejector->id, $reason);

        return $profile;
    }

    /**
     * Suspend an approved vendor. The Catalog module listens for
     * VendorSuspended and delists the vendor's approved products
     * (docs/firstmarket_Implementation_Plan.md Sprint 3 QA).
     */
    public function suspend(VendorProfile $profile, User $actor, string $reason): VendorProfile
    {
        if ($profile->status !== VendorStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => "Only approved vendors can be suspended; this vendor is {$profile->status->value}.",
            ]);
        }

        DB::transaction(function () use ($profile, $actor, $reason) {
            $profile->forceFill([
                'status' => VendorStatus::Suspended,
                'rejection_reason' => $reason,
            ])->save();

            $this->auditLogger->log(
                actor: $actor,
                subject: $profile,
                action: 'vendor.suspended',
                oldValues: ['status' => VendorStatus::Approved->value],
                newValues: ['status' => VendorStatus::Suspended->value, 'reason' => $reason],
            );
        });

        VendorSuspended::dispatch($profile->id, $profile->user_id, $actor->id, $reason);

        return $profile;
    }

    /**
     * Lift a suspension. Products stay Delisted — the vendor resubmits each
     * listing, so every one passes review again before customers see it.
     */
    public function reinstate(VendorProfile $profile, User $actor): VendorProfile
    {
        if ($profile->status !== VendorStatus::Suspended) {
            throw ValidationException::withMessages([
                'status' => "Only suspended vendors can be reinstated; this vendor is {$profile->status->value}.",
            ]);
        }

        DB::transaction(function () use ($profile, $actor) {
            $profile->forceFill([
                'status' => VendorStatus::Approved,
                'rejection_reason' => null,
            ])->save();

            $this->auditLogger->log(
                actor: $actor,
                subject: $profile,
                action: 'vendor.reinstated',
                oldValues: ['status' => VendorStatus::Suspended->value],
                newValues: ['status' => VendorStatus::Approved->value],
            );
        });

        return $profile;
    }

    private function assertPending(VendorProfile $profile): void
    {
        if ($profile->status !== VendorStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => "Only pending vendors can be reviewed; this vendor is already {$profile->status->value}.",
            ]);
        }
    }
}
