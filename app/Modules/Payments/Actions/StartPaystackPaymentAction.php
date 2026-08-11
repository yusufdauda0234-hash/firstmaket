<?php

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Logistics\Models\Shipment;
use App\Modules\Payments\Models\PaystackTransaction;
use App\Modules\Payments\Services\PaymentReconciler;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Contracts\PaymentGatewayContract;
use App\Shared\Enums\PaystackTransactionStatus;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hands the customer off to Paystack's hosted checkout, for the three things
 * money can be paid for: a card checkout, one instalment on a Pay Small
 * Small plan, or the goods balance on a delivered parcel.
 *
 * What the charge is *for* is recorded here, on our side, before the
 * customer leaves. The webhook reads it back from our own row rather than
 * from anything Paystack echoes, so a tampered callback cannot point someone
 * else's payment at a different order or plan.
 *
 * Before starting anything, every unfinished earlier attempt at the same
 * thing is put to Paystack directly. A customer who paid but never got the
 * webhook is credited and told so, rather than being invited to pay again —
 * which is the single most expensive bug a payment flow can have. Attempts
 * Paystack confirms are dead are removed rather than accumulating; anything
 * still in flight is left strictly alone.
 *
 * Nothing is credited here — only a verified charge moves money.
 */
class StartPaystackPaymentAction
{
    public function __construct(
        private readonly PaymentGatewayContract $gateway,
        private readonly PaymentReconciler $reconciler,
    ) {}

    /** Pay for a frozen, pending checkout session. */
    public function forCheckout(User $user, CheckoutSession $session): Response
    {
        if ($this->alreadySettled($user, ['checkout_session_id' => $session->id])) {
            return $this->alreadyPaid($session->paystack_reference);
        }

        /*
         * A fresh reference each attempt.
         *
         * Paystack rejects a reference it has already seen, so reusing the
         * one frozen onto the session would make the second attempt fail
         * outright. The session's own column is kept in step so the two
         * never disagree about which charge is current.
         */
        $reference = 'FMC_'.Str::lower((string) Str::ulid());
        $session->forceFill(['paystack_reference' => $reference])->save();

        return $this->start(
            user: $user,
            amountKobo: $session->total_amount_kobo,
            reference: $reference,
            attributes: ['purpose' => 'order', 'checkout_session_id' => $session->id],
        );
    }

    /** Pay one instalment into a plan. */
    public function forPlanInstallment(User $user, SavingsGoal $goal, int $amountKobo): Response
    {
        if ($this->alreadySettled($user, ['savings_goal_id' => $goal->id])) {
            return $this->alreadyPaid(null);
        }

        return $this->start(
            user: $user,
            amountKobo: $amountKobo,
            reference: 'FMP_'.Str::lower((string) Str::ulid()),
            attributes: ['purpose' => 'plan_installment', 'savings_goal_id' => $goal->id],
        );
    }

    /** Pay the goods balance attached to a delivered shipment. */
    public function forShipmentGoods(User $user, Shipment $shipment): Response
    {
        if ($shipment->collect_on_delivery_kobo <= 0 || $shipment->goods_paid_at !== null) {
            throw new \InvalidArgumentException('This shipment has no goods balance to pay.');
        }

        if ($this->alreadySettled($user, ['shipment_id' => $shipment->id])) {
            return $this->alreadyPaid(null);
        }

        return $this->start(
            user: $user,
            amountKobo: $shipment->collect_on_delivery_kobo,
            reference: 'FMG_'.Str::lower((string) Str::ulid()),
            attributes: ['purpose' => 'shipment_goods', 'shipment_id' => $shipment->id],
        );
    }

    /**
     * True when reconciling this customer's earlier attempts turned one of
     * them into a completed payment — so there is nothing left to charge.
     *
     * @param  array<string, int>  $subject
     */
    private function alreadySettled(User $user, array $subject): bool
    {
        return $this->reconciler->reconcileSubject($user->id, $subject)['settled'] > 0;
    }

    /** Send the customer to the callback screen, which reports the real state. */
    private function alreadyPaid(?string $reference): Response
    {
        return Inertia::location(route('payment.callback', array_filter(['reference' => $reference])));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function start(User $user, int $amountKobo, string $reference, array $attributes): Response
    {
        PaystackTransaction::query()->create([
            'user_id' => $user->id,
            'paystack_reference' => $reference,
            'amount_kobo' => $amountKobo,
            'currency' => 'NGN',
            'status' => PaystackTransactionStatus::Pending,
            ...$attributes,
        ]);

        $init = $this->gateway->initializeDeposit(
            user: $user,
            amountKobo: $amountKobo,
            reference: $reference,
            // Paystack's hosted page lets the customer pick between these.
            channels: ['card', 'bank_transfer', 'ussd'],
            callbackUrl: route('payment.callback'),
        );

        PaystackTransaction::query()
            ->where('paystack_reference', $reference)
            ->update(['access_code' => $init->accessCode]);

        // Inertia::location, not redirect(): the checkout form posts over XHR
        // and Paystack is a different origin, so a 302 would be followed by
        // the browser and then blocked for want of CORS headers.
        return Inertia::location($init->authorizationUrl);
    }
}
