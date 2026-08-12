<?php

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReceipt;
use App\Modules\Orders\Notifications\ReceiptIssuedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Issuing the customer's receipt for a checkout.
 */
class ReceiptService
{
    /**
     * Issue the receipt for a paid checkout, or return the one already
     * issued.
     *
     * Idempotent by the unique key on checkout_session_id: this runs inside
     * the payment webhook's transaction, and a webhook Paystack replays must
     * not hand the customer a second document with a different number for the
     * same money.
     *
     * @param  Collection<int, Order>  $orders
     */
    public function issueFor(User $customer, CheckoutSession $session, Collection $orders): ?OrderReceipt
    {
        if ($orders->isEmpty()) {
            return null;
        }

        $existing = OrderReceipt::query()->where('checkout_session_id', $session->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $lines = $this->lines($orders);

        $subtotal = $lines->sum(fn (array $line) => $line['lineTotalKobo']);
        $discount = (int) $orders->sum('promo_discount_kobo');
        $shipping = (int) $session->shipping_fee_kobo;
        $collectOnDelivery = (int) $session->collect_on_delivery_kobo;

        // Built from the orders that were actually raised, not from the
        // session total: anything the vendor could not supply has already
        // been dropped by then, and a receipt listing goods nobody is going
        // to deliver is the kind of paperwork that starts a dispute.
        $total = $subtotal + $shipping - $discount;

        return DB::transaction(function () use ($customer, $session, $lines, $subtotal, $shipping, $discount, $total, $collectOnDelivery) {
            $receipt = OrderReceipt::query()->create([
                // Placeholder — replaced below with a number derived from the
                // row's own id, which is the only value guaranteed unique
                // without taking a table lock. Two customers paying in the
                // same millisecond cannot collide.
                'receipt_number' => 'pending-'.$session->id,
                'checkout_session_id' => $session->id,
                'customer_id' => $customer->id,
                'subtotal_kobo' => $subtotal,
                'shipping_kobo' => $shipping,
                'discount_kobo' => $discount,
                'total_kobo' => max(0, $total),
                'paid_kobo' => max(0, $total - $collectOnDelivery),
                'collect_on_delivery_kobo' => $collectOnDelivery,
                'payment_method' => $session->payment_method,
                'payment_reference' => $session->paystack_reference,
                'items_snapshot' => $lines->all(),
                'billed_to' => [
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'phone' => $session->recipient_phone ?? $customer->phone,
                    'recipient' => $session->recipient_name,
                    'address' => $session->delivery_address,
                    'lga' => $session->lga,
                    'state' => $session->state,
                    'landmark' => $session->landmark,
                ],
                'issued_at' => now(),
            ]);

            $receipt->forceFill(['receipt_number' => $this->numberFor($receipt)])->save();

            return $receipt;
        });
    }

    /** Email the receipt to the customer. */
    public function email(OrderReceipt $receipt): void
    {
        $customer = $receipt->customer;

        if ($customer === null) {
            return;
        }

        $customer->notify(new ReceiptIssuedNotification(
            $receipt->uuid,
            $receipt->receipt_number,
            $receipt->total_kobo,
        ));

        $receipt->forceFill(['emailed_at' => now()])->save();
    }

    /**
     * One receipt line per product, with the units folded together.
     *
     * Orders are stored one row per unit — correct for delivery, wrong for a
     * document: nobody wants a receipt that lists the same kettle four times.
     *
     * No vendor name anywhere on the line. The storefront never shows a
     * customer who they bought from, and a receipt is not the place to start.
     *
     * @param  Collection<int, Order>  $orders
     * @return Collection<int, array<string, mixed>>
     */
    private function lines(Collection $orders): Collection
    {
        // Names looked up in one query rather than through the relation: the
        // orders arrive as a plain collection built row by row during
        // checkout, so there is nothing for loadMissing() to work on.
        $names = Product::query()
            ->whereIn('id', $orders->pluck('product_id')->unique())
            ->pluck('name', 'id');

        return $orders
            ->groupBy(fn (Order $order) => $order->product_id.':'.$order->locked_price_kobo)
            ->map(function (Collection $group) use ($names) {
                /** @var Order $first */
                $first = $group->first();
                $quantity = $group->count();

                return [
                    'name' => $names[$first->product_id] ?? 'Item',
                    'unitPriceKobo' => (int) $first->locked_price_kobo,
                    'quantity' => $quantity,
                    'lineTotalKobo' => (int) $first->locked_price_kobo * $quantity,
                ];
            })
            ->values();
    }

    /**
     * FM-2026-000417.
     *
     * The sequence is the row id, so numbers are unique and monotonic without
     * a counter to contend over. The year is for the reader, not the
     * uniqueness — ids never restart.
     */
    private function numberFor(OrderReceipt $receipt): string
    {
        return sprintf('FM-%s-%06d', $receipt->issued_at->format('Y'), $receipt->id);
    }
}
