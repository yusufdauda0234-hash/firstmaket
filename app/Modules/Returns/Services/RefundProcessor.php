<?php

namespace App\Modules\Returns\Services;

use App\Models\User;
use App\Modules\Affiliates\Models\AffiliateCommission;
use App\Modules\Affiliates\Models\AffiliateConversion;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaystackTransaction;
use App\Modules\Returns\Models\Refund;
use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Savings\Services\SavingsService;
use App\Modules\Vendor\Services\EarningsService;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Contracts\PaymentGatewayContract;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sending money back, and unwinding everything the sale set in motion.
 *
 * This is the only place in the system where money moves outward, so the
 * guarantees are stated plainly:
 *
 * - Admin-only. `$admin` is recorded on the row; nothing a customer or a
 *   scheduler can reach calls this.
 * - Capped. Never more than the refundable amount snapshotted when the case
 *   was opened, which is itself the goods price less any promo discount.
 * - Exactly once. `refunds.gateway_reference` is unique in the database, so a
 *   retried or double-submitted refund cannot pay twice — the second insert
 *   simply cannot exist.
 * - Never cash out of a plan. An order that came from a Pay Small Small plan
 *   refunds as credit on that plan, because money paid into a plan has never
 *   been withdrawable and a return is not a way to make it so.
 */
class RefundProcessor
{
    public function __construct(
        private readonly PaymentGatewayContract $gateway,
        private readonly EarningsService $earnings,
        private readonly SavingsService $savings,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    public function process(User $admin, ReturnRequest $request): Refund
    {
        $order = $request->order;

        if ($order === null) {
            throw ValidationException::withMessages(['order' => 'This return has no order attached.']);
        }

        $amountKobo = min($request->refundable_kobo, $this->alreadyRefundableKobo($order));

        if ($amountKobo <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'There is nothing left to refund on this order.',
            ]);
        }

        // Plan orders return value to the plan; everything else goes back the
        // way it came.
        $toPlan = $order->savings_goal_id !== null;

        return DB::transaction(function () use ($admin, $request, $order, $amountKobo, $toPlan) {
            $refund = $this->createRefundRow($admin, $request, $order, $amountKobo, $toPlan);

            if ($toPlan) {
                $this->savings->creditFromCancelledPlan(
                    $order->customer,
                    $amountKobo,
                    $order->savingsGoal?->uuid ?? $order->uuid,
                );

                $refund->forceFill([
                    'status' => Refund::STATUS_COMPLETED,
                    'completed_at' => now(),
                ])->save();
            } else {
                $this->refundToCard($refund, $order, $amountKobo);
            }

            $this->reverseSaleEffects($order, $request);

            $this->auditLogger->log(
                actor: $admin,
                subject: $refund,
                action: 'returns.refund_issued',
                newValues: [
                    'order_uuid' => $order->uuid,
                    'amount_kobo' => $amountKobo,
                    'destination' => $refund->destination,
                ],
            );

            return $refund;
        });
    }

    /**
     * Reserve the refund before any money moves.
     *
     * The unique reference is generated and inserted first precisely so that a
     * concurrent second attempt collides here, before the gateway is called,
     * rather than after it has already paid.
     */
    private function createRefundRow(
        User $admin,
        ReturnRequest $request,
        Order $order,
        int $amountKobo,
        bool $toPlan,
    ): Refund {
        try {
            return Refund::query()->create([
                'return_request_id' => $request->id,
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'issued_by' => $admin->id,
                'amount_kobo' => $amountKobo,
                'destination' => $toPlan ? Refund::DESTINATION_PLAN_CREDIT : Refund::DESTINATION_CARD,
                'status' => Refund::STATUS_PENDING,
                // Derived from the return, not random: two attempts at the
                // same return produce the same key and the second one is
                // rejected by the unique index.
                'gateway_reference' => 'FMR_'.$request->uuid,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'refund' => 'A refund has already been issued for this return.',
            ]);
        }
    }

    private function refundToCard(Refund $refund, Order $order, int $amountKobo): void
    {
        $originalReference = $this->originalChargeReference($order);

        if ($originalReference === null) {
            $refund->forceFill([
                'status' => Refund::STATUS_FAILED,
                'failure_reason' => 'The original payment for this order could not be found.',
            ])->save();

            throw ValidationException::withMessages([
                'refund' => 'The original payment for this order could not be found, so it cannot be refunded to card. Resolve it manually.',
            ]);
        }

        $attempt = $this->gateway->refund($originalReference, $amountKobo);

        if (! $attempt->succeeded && ! $attempt->isInFlight()) {
            $refund->forceFill([
                'status' => Refund::STATUS_FAILED,
                'failure_reason' => Str::limit($attempt->message ?? 'The refund was rejected.', 250),
            ])->save();

            // Rolls the whole transaction back, so the return does not end up
            // marked refunded with no money sent.
            throw ValidationException::withMessages([
                'refund' => $attempt->message ?? 'The payment provider rejected the refund.',
            ]);
        }

        $refund->forceFill([
            'status' => Refund::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Unwind what the delivered sale paid out.
     *
     * Order matters less than completeness: a return that refunds the customer
     * but leaves the vendor paid, the affiliate commissioned and the promo code
     * spent has simply moved the loss onto the platform without recording it
     * anywhere.
     */
    private function reverseSaleEffects(Order $order, ReturnRequest $request): void
    {
        // Vendor earnings, if the delivery was already confirmed and credited.
        if ($order->earnings_credited_at !== null && $order->vendor_earning_amount_kobo > 0) {
            $this->earnings->clawBackOrderEarning(
                vendorId: $order->vendor_id,
                orderId: $order->id,
                amountKobo: $order->vendor_earning_amount_kobo,
                note: 'Order returned — return '.$request->uuid,
            );
        }

        // Affiliate commission: a returned order is not an acquisition. The
        // rows are voided rather than deleted so the affiliate can see what
        // happened and why their balance moved.
        $conversion = AffiliateConversion::query()->where('order_id', $order->id)->first();

        if ($conversion !== null) {
            AffiliateCommission::query()
                ->where('conversion_id', $conversion->id)
                ->update(['status' => 'reversed']);

            $conversion->update(['status' => 'reversed']);
        }
    }

    /**
     * How much of this order is still refundable.
     *
     * Guards against a second return on the same order taking the money twice,
     * independently of the unique reference — belt and braces, because this is
     * the one place a bug costs real money.
     */
    private function alreadyRefundableKobo(Order $order): int
    {
        $paid = max(0, $order->locked_price_kobo - (int) $order->promo_discount_kobo);

        $refunded = (int) Refund::query()
            ->where('order_id', $order->id)
            ->where('status', Refund::STATUS_COMPLETED)
            ->sum('amount_kobo');

        return max(0, $paid - $refunded);
    }

    /**
     * The reference of the charge that paid for this order.
     *
     * Paystack refunds against the original transaction, which is what keeps
     * this a reversal rather than a payout — money can only travel back the
     * way it came.
     */
    private function originalChargeReference(Order $order): ?string
    {
        return PaystackTransaction::query()
            ->when(
                $order->checkout_session_id !== null,
                fn ($query) => $query->where('checkout_session_id', $order->checkout_session_id),
                fn ($query) => $query->where('savings_goal_id', $order->savings_goal_id),
            )
            ->whereNotNull('webhook_verified_at')
            ->latest('id')
            ->value('paystack_reference');
    }
}
