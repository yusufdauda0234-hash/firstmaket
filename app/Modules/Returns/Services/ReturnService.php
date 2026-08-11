<?php

namespace App\Modules\Returns\Services;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Returns\Models\Refund;
use App\Modules\Returns\Models\ReturnEvent;
use App\Modules\Returns\Models\ReturnRequest;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\ReturnReason;
use App\Shared\Enums\ReturnStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The return workflow, end to end.
 *
 * Requested → Approved → InTransit → Received → Refunded, with Rejected and
 * Disputed hanging off the review and inspection steps. Every transition is
 * written to `return_events` with the actor, because a money decision nobody
 * can reconstruct afterwards is a money decision nobody can defend.
 *
 * The division of authority is the important part. A customer opens and may
 * cancel a case. A vendor confirms what physically came back and may contest
 * its condition — but cannot decide the outcome, because the vendor is the
 * party who loses the sale. Only an admin approves, rejects, or moves money.
 */
class ReturnService
{
    public function __construct(
        private readonly ReturnPolicy $policy,
        private readonly RefundProcessor $refunds,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    /**
     * Open a case.
     *
     * The policy terms are snapshotted onto the row here, so a later change to
     * the published policy cannot rewrite the terms this customer agreed to.
     */
    public function open(User $customer, Order $order, ReturnReason $reason, ?string $note = null): ReturnRequest
    {
        if ($order->customer_id !== $customer->id) {
            throw ValidationException::withMessages(['order' => 'This order does not belong to you.']);
        }

        $refusal = $this->policy->refusalReason($order, $reason);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['order' => $refusal]);
        }

        return DB::transaction(function () use ($customer, $order, $reason, $note) {
            $request = ReturnRequest::query()->create([
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'vendor_id' => $order->vendor_id,
                'reason' => $reason,
                'reason_note' => $note,
                'status' => ReturnStatus::Requested,
                'policy_window_days' => $this->policy->windowDays(),
                'return_delivery_paid_by' => $reason->returnDeliveryPaidBy(),
                'required_unopened' => $reason->requiresUnopened(),
                'refundable_kobo' => $this->policy->refundableKobo($order),
            ]);

            $this->recordEvent($request, null, ReturnStatus::Requested, $customer, $note);

            $this->auditLogger->log(
                actor: $customer,
                subject: $request,
                action: 'returns.request_opened',
                newValues: ['order_uuid' => $order->uuid, 'reason' => $reason->value],
            );

            return $request;
        });
    }

    /** The customer changes their mind about the return itself. */
    public function cancel(User $customer, ReturnRequest $request): ReturnRequest
    {
        $this->assertOwnedBy($request, $customer);

        if (! $request->status->isCancellable()) {
            throw ValidationException::withMessages([
                'status' => 'This return has gone too far to be cancelled. Contact support.',
            ]);
        }

        return $this->transition($request, ReturnStatus::Cancelled, $customer, 'Cancelled by the customer.');
    }

    /** Admin decision: yes, send it back. */
    public function approve(User $admin, ReturnRequest $request, ?string $note = null): ReturnRequest
    {
        $this->assertStatus($request, [ReturnStatus::Requested, ReturnStatus::Disputed]);

        $request->forceFill([
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        return $this->transition($request, ReturnStatus::Approved, $admin, $note);
    }

    /** Admin decision: no, and here is why. */
    public function reject(User $admin, ReturnRequest $request, string $reason): ReturnRequest
    {
        $this->assertStatus($request, [ReturnStatus::Requested, ReturnStatus::Received, ReturnStatus::Disputed]);

        $request->forceFill([
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'review_note' => $reason,
            'resolved_at' => now(),
        ])->save();

        return $this->transition($request, ReturnStatus::Rejected, $admin, $reason);
    }

    /** The customer has handed it to the courier. */
    public function markInTransit(User $actor, ReturnRequest $request): ReturnRequest
    {
        $this->assertStatus($request, [ReturnStatus::Approved]);

        return $this->transition($request, ReturnStatus::InTransit, $actor);
    }

    /**
     * The vendor confirms the item physically arrived.
     *
     * Receiving it is a fact the vendor is best placed to report. Judging it
     * is not theirs to do, which is why `contest` is a separate call that
     * hands the case to an admin rather than closing it.
     */
    public function markReceived(User $vendorUser, ReturnRequest $request): ReturnRequest
    {
        $this->assertStatus($request, [ReturnStatus::InTransit, ReturnStatus::Approved]);

        $request->forceFill(['received_at' => now()])->save();

        return $this->transition($request, ReturnStatus::Received, $vendorUser);
    }

    /**
     * The vendor says the item came back in a different state than described.
     *
     * This never rejects the return — it escalates it. The vendor has an
     * obvious interest in the outcome, so the decision goes to an admin.
     */
    public function contest(User $vendorUser, ReturnRequest $request, string $reason): ReturnRequest
    {
        $this->assertStatus($request, [ReturnStatus::Received, ReturnStatus::InTransit]);

        return $this->transition($request, ReturnStatus::Disputed, $vendorUser, $reason);
    }

    /**
     * Uphold the return and send the money back.
     *
     * Admin-only, and the last step: the refund itself, the vendor clawback
     * and the affiliate/promo reversals all happen inside one transaction, so
     * a case can never end up refunded with the vendor still paid for it.
     */
    public function refund(User $admin, ReturnRequest $request, ?string $note = null): ReturnRequest
    {
        $this->assertStatus($request, [ReturnStatus::Received, ReturnStatus::Disputed, ReturnStatus::Approved]);

        return DB::transaction(function () use ($admin, $request, $note) {
            $this->refunds->process($admin, $request);

            $request->forceFill([
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => $note ?? $request->review_note,
                'resolved_at' => now(),
            ])->save();

            return $this->transition($request, ReturnStatus::Refunded, $admin, $note);
        });
    }

    // ── internals ──────────────────────────────────────────────────────────

    private function transition(
        ReturnRequest $request,
        ReturnStatus $to,
        ?User $actor,
        ?string $note = null,
    ): ReturnRequest {
        $from = $request->status;

        $request->forceFill(['status' => $to])->save();
        $this->recordEvent($request, $from, $to, $actor, $note);

        $this->auditLogger->log(
            actor: $actor,
            subject: $request,
            action: 'returns.status_changed',
            oldValues: ['status' => $from->value],
            newValues: ['status' => $to->value],
        );

        return $request->refresh();
    }

    private function recordEvent(
        ReturnRequest $request,
        ?ReturnStatus $from,
        ReturnStatus $to,
        ?User $actor,
        ?string $note = null,
    ): void {
        ReturnEvent::query()->create([
            'return_request_id' => $request->id,
            'actor_id' => $actor?->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note === null ? null : Str::limit($note, 480),
        ]);
    }

    private function assertOwnedBy(ReturnRequest $request, User $customer): void
    {
        if ($request->customer_id !== $customer->id) {
            throw ValidationException::withMessages(['return' => 'This return does not belong to you.']);
        }
    }

    /** @param  list<ReturnStatus>  $allowed */
    private function assertStatus(ReturnRequest $request, array $allowed): void
    {
        if (! in_array($request->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => 'This return is '.$request->status->label().' and cannot move that way.',
            ]);
        }
    }
}
