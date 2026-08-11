<?php

namespace App\Modules\Cart\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Services\CommissionRate;
use App\Modules\Orders\Services\DeliveryPricing;
use Illuminate\Support\Collection;

/**
 * The money side of a cart, computed in one place so the cart page, the
 * checkout page and the actual savings debit can never disagree. Every figure
 * is integer kobo (docs/FirstMaket_Implementation_Plan.md "Key Engineering
 * Rules").
 *
 * "Items total" is what the goods would cost at the vendors' compare-at
 * prices and "discount" is what FirstMaket's price saves against that, so
 * the two always reconcile to the subtotal.
 */
readonly class CartSummary
{
    public function __construct(
        public int $itemsTotalKobo,
        public int $discountKobo,
        public int $subtotalKobo,
        public int $shippingKobo,
        public int $totalKobo,
        public int $itemCount,
        // Carried so the page can say "spend X more for free delivery"
        // with the threshold that actually applies to this state.
        public int $freeThresholdKobo = 0,
    ) {}

    /**
    * @param  Collection<int, array{cartItemId: int|null, product: Product, quantity: int, unitPriceKobo?: int}>  $lines
     * @param  string|null  $state  Delivery state, once it is known. The cart
     *                              page quotes the default rate because no
     *                              address has been given yet; checkout
     *                              passes the real one.
     */
    public static function fromLines(Collection $lines, ?string $state = null): self
    {
        $subtotal = 0;
        $itemsTotal = 0;
        $count = 0;

        foreach ($lines as $line) {
            /** @var Product $product */
            $product = $line['product'];
            $quantity = $line['quantity'];

            $unitPrice = (int) ($line['unitPriceKobo'] ?? $product->price_kobo);
            $subtotal += $unitPrice * $quantity;
            // Products without a compare-at price contribute their own price,
            // so they show no phantom discount.
            $itemsTotal += max($product->compare_at_price_kobo ?? 0, $unitPrice) * $quantity;
            $count += $quantity;
        }

        $shipping = self::shippingFor($subtotal, $state);

        return new self(
            itemsTotalKobo: $itemsTotal,
            discountKobo: $itemsTotal - $subtotal,
            subtotalKobo: $subtotal,
            shippingKobo: $shipping,
            totalKobo: $subtotal + $shipping,
            itemCount: $count,
            freeThresholdKobo: app(DeliveryPricing::class)->freeThresholdKobo($state),
        );
    }

    /**
     * The delivery fee, from the rates admins maintain in the admin portal.
     * Falls back to the configured flat fee when no rate row applies.
     */
    public static function shippingFor(int $subtotalKobo, ?string $state = null): int
    {
        return app(DeliveryPricing::class)->feeKobo($subtotalKobo, $state);
    }

    /**
     * Total commission FirstMaket would earn on this cart.
     *
     * Promo discounts are platform-funded, so this is the ceiling on what any
     * code may take off: past it the discount would come out of the vendors'
     * earnings, which they never agreed to. Computed with the same resolver
     * that snapshots commission onto the orders, so the cap and the eventual
     * accounting agree.
     *
     * @param  Collection<int, array{cartItemId: int|null, product: Product, quantity: int}>  $lines
     */
    public static function commissionKoboFor(Collection $lines): int
    {
        $commission = 0;

        foreach ($lines as $line) {
            /** @var Product $product */
            $product = $line['product'];

            $commission += CommissionRate::for($product, $product->price_kobo)
                ->onKobo($product->price_kobo) * $line['quantity'];
        }

        return $commission;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'itemsTotalKobo' => $this->itemsTotalKobo,
            'discountKobo' => $this->discountKobo,
            'subtotalKobo' => $this->subtotalKobo,
            'shippingKobo' => $this->shippingKobo,
            'totalKobo' => $this->totalKobo,
            'itemCount' => $this->itemCount,
            'freeShippingThresholdKobo' => $this->freeThresholdKobo,
        ];
    }
}
