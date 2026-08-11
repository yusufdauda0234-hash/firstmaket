<?php

namespace App\Modules\Cart\Services;

use App\Models\User;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CampaignService;
use App\Modules\Logistics\Services\ShipmentBuilder;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\PromoRedeemer;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\CheckoutMethod;
use App\Shared\Enums\ProductStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cart checkout paid by card.
 *
 * There is no customer balance any more, so a checkout cannot be settled
 * inside the request that starts it: the shopper leaves for Paystack, and
 * only the verified webhook can say the money arrived. The session is
 * therefore created `pending` with the basket and address frozen onto it —
 * the cart could be edited, or a vendor could reprice, between the redirect
 * out and the webhook coming back, and the customer must get what they saw.
 *
 * Availability is checked twice: once when the session is created (so nobody
 * pays for something already sold out) and again on completion, because a
 * hosted checkout can sit open for a long time. Order creation is delegated
 * to OrderService, which owns every order state change.
 */
class CartCheckoutService
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly PromoRedeemer $promoRedeemer,
        private readonly ShipmentBuilder $shipmentBuilder,
        private readonly AuditLoggerContract $auditLogger,
        private readonly CampaignService $campaignService,
    ) {}

    /**
     * Freeze the cart into a pending session ready to be paid for.
     *
     * @param  Collection<int, array{cartItemId: int|null, product: Product, quantity: int}>  $lines
     * @param  array<string, mixed>  $address  Validated delivery fields.
     * @param  string|null  $promoCode  Re-quoted here rather than trusted from
     *                                  the page: the code may have expired or
     *                                  run out since it was applied, and the
     *                                  cart may have changed under it.
     */
    public function startCardCheckout(
        User $user,
        Collection $lines,
        array $address,
        ?string $promoCode = null,
        bool $payOnDelivery = false,
    ): CheckoutSession {
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['cart' => 'Your cart is empty.']);
        }

        $purchasable = collect($this->purchasableFrom($lines))->map(function (array $line) {
            $pricing = $this->campaignService->priceFor($line['product'], $line['quantity']);
            return $line + [
                'unitPriceKobo' => $pricing['unitPriceKobo'],
                'campaignProductId' => $pricing['campaignProductId'],
            ];
        })->all();

        if ($purchasable === []) {
            throw ValidationException::withMessages([
                'cart' => 'Nothing in your cart is available right now.',
            ]);
        }

        // The address is in hand here, so this is the authoritative price —
        // it is what the customer is actually charged.
        $summary = CartSummary::fromLines(collect($purchasable), $address['state'] ?? null);

        $promo = $promoCode === null ? null : $this->promoRedeemer->quote(
            $user,
            $promoCode,
            $summary->subtotalKobo,
            $summary->shippingKobo,
            CartSummary::commissionKoboFor($lines),
        );

        $discountKobo = ($promo['discountKobo'] ?? 0) + ($promo['deliveryDiscountKobo'] ?? 0);

        $payableNow = max(0, $summary->totalKobo - $discountKobo);

        // Pay on delivery charges the delivery fee now and the goods at the
        // door. The fee is taken upfront on purpose: it is what a refused
        // delivery actually costs FirstMaket, so the customer carries that
        // risk rather than the business carrying it for free.
        $collectAtDoorKobo = 0;

        if ($payOnDelivery) {
            $collectAtDoorKobo = max(0, $payableNow - $summary->shippingKobo);
            $payableNow = $summary->shippingKobo;
        }

        return CheckoutSession::query()->create([
            'user_id' => $user->id,
            'savings_transaction_id' => null,
            // Net of the discount: this is the figure sent to Paystack, so a
            // promo that did not reach it would be a promise, not a discount.
            'total_amount_kobo' => $payableNow,
            'shipping_fee_kobo' => $summary->shippingKobo,
            'payment_method' => $payOnDelivery
                ? CheckoutMethod::PayOnDelivery->value
                : CheckoutMethod::Card->value,
            'promo_code_id' => $promo['code']->id ?? null,
            'promo_discount_kobo' => $discountKobo,
            'collect_on_delivery_kobo' => $collectAtDoorKobo,
            'status' => 'pending',
            'paystack_reference' => 'FMC_'.Str::lower((string) Str::ulid()),
            'items_snapshot' => collect($purchasable)->map(fn (array $item) => [
                'product_id' => $item['product']->id,
                'quantity' => $item['quantity'],
                'unit_price_kobo' => $item['unitPriceKobo'],
                'campaign_product_id' => $item['campaignProductId'],
                // Remembered so the webhook can clear exactly the rows this
                // checkout came from. A Buy-now line stores null and therefore
                // never touches the cart.
                'cart_item_id' => $item['cartItemId'],
            ])->all(),
            'delivery_address' => $address['delivery_address'],
            'state' => $address['state'],
            'lga' => $address['lga'],
            'recipient_name' => $address['recipient_name'] ?? null,
            'recipient_phone' => $address['recipient_phone'] ?? null,
            'landmark' => $address['landmark'] ?? null,
            'created_at' => now(),
        ]);
    }

    /**
     * Turn a paid session into orders. Called only from the verified
     * Paystack webhook — never from a browser request.
     *
     * @return array{orders: Collection<int, Order>, skippedProductNames: array<int, string>}
     */
    public function completePaidSession(CheckoutSession $session): array
    {
        return DB::transaction(function () use ($session) {
            /** @var CheckoutSession $session */
            $session = CheckoutSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            // Idempotency: a replayed webhook must not raise the orders twice.
            if ($session->status === 'paid') {
                return ['orders' => $session->orders()->get(), 'skippedProductNames' => []];
            }

            $user = $session->user;
            $purchasable = [];
            $skipped = [];

            foreach ($session->items_snapshot ?? [] as $line) {
                $product = Product::query()->whereKey($line['product_id'])->lockForUpdate()->first();

                if ($product === null
                    || $product->status !== ProductStatus::Approved
                    || $line['quantity'] > $product->stock_quantity
                ) {
                    $skipped[] = $product->name ?? 'An item';

                    continue;
                }

                $purchasable[] = [
                    'product' => $product,
                    'quantity' => $line['quantity'],
                    // Charge what they were shown, not what it costs today.
                    'unitPriceKobo' => $line['unit_price_kobo'],
                    'campaignProductId' => $line['campaign_product_id'] ?? null,
                    // Absent on sessions frozen before Buy-now existed.
                    'cartItemId' => $line['cart_item_id'] ?? null,
                    'hasCartItemKey' => array_key_exists('cart_item_id', $line),
                ];
            }

            // The code is spent here, not when it was applied: an abandoned
            // checkout must not burn a use, and a limited code should go to
            // whoever actually paid. Idempotent, so a replayed webhook that
            // got past the status check above still cannot double-spend it.
            if ($session->promo_code_id !== null && $session->promo_discount_kobo > 0) {
                $this->promoRedeemer->redeem(
                    $user,
                    $session->promoCode,
                    $session->id,
                    $session->promo_discount_kobo,
                );
            }

            // A lost stock-cap race here must not abort the transaction: this
            // runs inside the verified webhook, and an uncaught exception
            // would roll back the `status = paid` write too, leaving the
            // customer charged with no order and the webhook retrying
            // forever. Treat it like any other line that turned out to be
            // unavailable between checkout start and payment completion.
            $purchasable = array_values(array_filter($purchasable, function (array $line) use (&$skipped) {
                if (($line['campaignProductId'] ?? null) === null) {
                    return true;
                }

                if ($this->campaignService->reserve($line['campaignProductId'], $line['quantity'])) {
                    return true;
                }

                $skipped[] = $line['product']->name;

                return false;
            }));

            $orders = $purchasable === []
                ? collect()
                : $this->orderService->createFromCheckoutSession(
                    $user,
                    $session,
                    $this->withApportionedDiscount($purchasable, (int) $session->promo_discount_kobo),
                );

            // Goods and money are settled; now say how the goods travel. One
            // parcel per vendor, so a two-vendor basket is two pickups and a
            // three-unit line is still one box.
            $this->shipmentBuilder->fromCheckout($session, $orders);

            $session->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

            // Only what was actually bought leaves the cart, matched on the
            // exact cart rows this checkout froze. A Buy-now line carries no
            // cart row, so an express purchase leaves the saved cart alone —
            // even when the same product is sitting in it.
            $boughtCartItemIds = collect($purchasable)
                ->filter(fn (array $item) => $item['cartItemId'] !== null)
                ->pluck('cartItemId');

            if ($boughtCartItemIds->isNotEmpty()) {
                CartItem::query()
                    ->whereIn('id', $boughtCartItemIds)
                    ->whereHas('cart', fn ($cart) => $cart->where('user_id', $user->id))
                    ->delete();
            }

            // Sessions frozen before cart_item_id was recorded fall back to
            // the old product-id sweep so an in-flight checkout still clears.
            $legacyProductIds = collect($purchasable)
                ->reject(fn (array $item) => $item['hasCartItemKey'])
                ->map(fn (array $item) => $item['product']->id);

            if ($legacyProductIds->isNotEmpty()) {
                CartItem::query()
                    ->whereIn('product_id', $legacyProductIds)
                    ->whereHas('cart', fn ($cart) => $cart->where('user_id', $user->id))
                    ->delete();
            }

            $this->auditLogger->log(
                actor: $user,
                subject: $session,
                action: 'cart.checkout_paid_by_card',
                newValues: [
                    'total_amount_kobo' => $session->total_amount_kobo,
                    'order_count' => $orders->count(),
                    'skipped_count' => count($skipped),
                ],
            );

            return ['orders' => $orders, 'skippedProductNames' => $skipped];
        });
    }

    /**
     * Spread the basket's promo discount across the individual units.
     *
     * Orders are one row per unit, so "₦5,000 off" has to become a per-unit
     * figure before it can be written down — a refund or a payout needs this
     * unit's share, not the basket's total. Apportioned across units rather
     * than lines because that is the granularity the orders are stored at,
     * and by price so a ₦200,000 fridge absorbs more of the discount than a
     * ₦2,000 kettle sitting beside it.
     *
     * Anything the vendor could not supply is already out of $items, so the
     * discount lands only on goods that actually shipped.
     *
     * @param  array<int, array{product: Product, quantity: int, unitPriceKobo?: int}>  $items
     * @return array<int, array<string, mixed>>
     */
    private function withApportionedDiscount(array $items, int $discountKobo): array
    {
        if ($discountKobo <= 0) {
            return $items;
        }

        // One entry per unit, keyed "lineIndex:unitIndex" so each share can be
        // handed back to the unit it was computed for.
        $unitPrices = [];

        foreach ($items as $lineIndex => $item) {
            for ($unit = 0; $unit < $item['quantity']; $unit++) {
                $unitPrices["$lineIndex:$unit"] = $item['unitPriceKobo'] ?? $item['product']->price_kobo;
            }
        }

        $shares = $this->promoRedeemer->apportion($discountKobo, $unitPrices);

        foreach ($shares as $key => $share) {
            [$lineIndex, $unit] = explode(':', (string) $key);
            $items[(int) $lineIndex]['promoDiscountPerUnitKobo'][(int) $unit] = $share;
        }

        return $items;
    }

    /**
     * @param  Collection<int, array{cartItemId: int|null, product: Product, quantity: int}>  $lines
     * @return array<int, array{product: Product, quantity: int}>
     */
    private function purchasableFrom(Collection $lines): array
    {
        $purchasable = [];

        foreach ($lines as $line) {
            $product = $line['product'];

            if ($product->status === ProductStatus::Approved && $line['quantity'] <= $product->stock_quantity) {
                $purchasable[] = [
                    'product' => $product,
                    'quantity' => $line['quantity'],
                    // Null for a Buy-now line, which has no cart row behind it.
                    'cartItemId' => $line['cartItemId'] ?? null,
                ];
            }
        }

        return $purchasable;
    }
}
