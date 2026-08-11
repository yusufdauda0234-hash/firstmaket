<?php

namespace App\Modules\Returns\Services;

use App\Models\Setting;
use App\Modules\Orders\Models\Order;
use App\Modules\Returns\Models\ReturnRequest;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\ReturnReason;
use App\Shared\Enums\ReturnStatus;

/**
 * The single place that answers "can this come back, and on what terms".
 *
 * It exists so the published policy and the enforced policy cannot drift. The
 * returns panel on the product page and the guard in the service both read
 * from here, which means the sentence a customer is shown is generated from
 * the same numbers that will later refuse or accept their request.
 *
 * The terms themselves come from config so they can be tuned without a
 * deploy, but every one of them defaults to exactly what the storefront
 * currently promises.
 */
class ReturnPolicy
{
    /**
     * Days from delivery in which a problem can be reported.
     *
     * Read from the settings table so staff can change it without a deploy;
     * the config value is the fallback the system ships with, and is what a
     * fresh install uses until somebody edits it.
     */
    public function windowDays(): int
    {
        return (int) Setting::get('returns.window_days', config('firstmaket.returns.window_days', 7));
    }

    /** Working days quoted to the customer for money to reach their card. */
    public function refundDaysMin(): int
    {
        return (int) Setting::get('returns.refund_days_min', config('firstmaket.returns.refund_days_min', 5));
    }

    public function refundDaysMax(): int
    {
        return (int) Setting::get('returns.refund_days_max', config('firstmaket.returns.refund_days_max', 10));
    }

    /**
     * When the window closes for this order, or null if it has not started.
     *
     * Measured from delivery, not from the order date: an order that spent
     * three weeks in transit has not used up its return window in transit.
     */
    public function windowClosesAt(Order $order): ?\Illuminate\Support\Carbon
    {
        return $order->delivered_at?->copy()->addDays($this->windowDays());
    }

    public function isWithinWindow(Order $order): bool
    {
        $closesAt = $this->windowClosesAt($order);

        return $closesAt !== null && $closesAt->isFuture();
    }

    /**
     * Everything standing between this order and a return, in the order a
     * person would think of them.
     *
     * Returns null when a return may be opened. The string is shown to the
     * customer, so each one says what to do about it rather than only what is
     * wrong.
     */
    public function refusalReason(Order $order, ReturnReason $reason): ?string
    {
        if ($order->status !== OrderStatus::Delivered) {
            return 'This order has not been delivered yet, so there is nothing to send back.';
        }

        if ($order->delivered_at === null) {
            return 'We do not have a delivery date for this order. Please contact support.';
        }

        if (! $this->isWithinWindow($order)) {
            return 'The '.$this->windowDays().'-day return window for this order has closed.';
        }

        // A category flagged as change-of-mind-excluded can still come back
        // faulty — both the published policy and consumer law require it.
        $categoryAllows = $order->product?->category?->returnable_on_change_of_mind ?? true;

        if (! $categoryAllows && ! $reason->overridesCategoryExclusion()) {
            return 'Items in this category can only be returned if they arrive damaged, faulty, or not as described.';
        }

        if ($this->hasOpenRequest($order)) {
            return 'There is already an open return for this order.';
        }

        if ($this->hasCompletedReturn($order)) {
            return 'This order has already been returned.';
        }

        return null;
    }

    public function canOpen(Order $order, ReturnReason $reason): bool
    {
        return $this->refusalReason($order, $reason) === null;
    }

    /**
     * What may be sent back if the return is upheld.
     *
     * The goods total less any promo discount — the customer is refunded what
     * they actually paid, not the list price, or a code would turn a return
     * into a profit. Delivery already spent on getting it there is not
     * refunded on a change of mind; where the fault was ours it is handled as
     * part of covering the return delivery.
     */
    public function refundableKobo(Order $order): int
    {
        return max(0, $order->locked_price_kobo - (int) $order->promo_discount_kobo);
    }

    private function hasOpenRequest(Order $order): bool
    {
        return ReturnRequest::query()
            ->where('order_id', $order->id)
            ->whereNotIn('status', [
                ReturnStatus::Rejected->value,
                ReturnStatus::Refunded->value,
                ReturnStatus::Cancelled->value,
            ])
            ->exists();
    }

    private function hasCompletedReturn(Order $order): bool
    {
        return ReturnRequest::query()
            ->where('order_id', $order->id)
            ->where('status', ReturnStatus::Refunded)
            ->exists();
    }
}
