<?php

namespace App\Modules\Cart\Services;

use App\Models\User;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Models\Product;
use App\Shared\Enums\ProductStatus;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Owns cart mutations (docs/FirstMaket_Implementation_Plan.md Sprint 8).
 *
 * Guests and signed-in shoppers share one storage path — the carts table.
 * The only difference is how the row is found: by user_id for an account, by
 * a long-lived cookie token for a guest (see GuestCart). That keeps the two
 * from drifting apart, and means a guest cart survives for months rather
 * than dying with the PHP session.
 *
 * Everything is addressed by Product rather than by cart-item id, so the
 * caller can only ever reach lines in their own cart.
 *
 * Product Approved/in-stock status is checked on every mutation, not just on
 * add — CartCheckoutService re-validates once more at payment time, since a
 * product can change state between add-to-cart and checkout.
 */
class CartService
{
    public function __construct(private readonly GuestCart $guestCart) {}

    /** Every signed-in customer has exactly one cart; create it lazily. */
    public function getOrCreate(User $user): Cart
    {
        return Cart::query()->firstOrCreate(['user_id' => $user->id]);
    }

    /**
     * The cart to act on. Writes pass $create so a guest's row and cookie
     * are minted on demand; reads do not, so browsing mints nothing.
     */
    public function cartFor(?User $user, bool $create = false): ?Cart
    {
        if ($user !== null) {
            return $create
                ? $this->getOrCreate($user)
                : Cart::query()->where('user_id', $user->id)->first();
        }

        return $this->guestCart->cart($create);
    }

    /**
     * Add a product, or increase its quantity if already present. The
     * resulting total quantity is what gets stock-checked, so a shopper
     * cannot walk past stock one unit at a time.
     */
    public function addItem(?User $user, Product $product, int $quantity = 1): void
    {
        $this->setQuantity($user, $product, $this->quantityOf($user, $product) + $quantity);
    }

    public function setQuantity(?User $user, Product $product, int $quantity): void
    {
        $this->assertPurchasable($product, $quantity);

        CartItem::query()->updateOrCreate(
            ['cart_id' => $this->cartFor($user, create: true)->id, 'product_id' => $product->id],
            ['quantity' => $quantity],
        );
    }

    public function removeItem(?User $user, Product $product): void
    {
        $cart = $this->cartFor($user);

        $cart?->items()->where('product_id', $product->id)->delete();
    }

    public function quantityOf(?User $user, Product $product): int
    {
        $cart = $this->cartFor($user);

        return (int) $cart?->items()->where('product_id', $product->id)->value('quantity');
    }

    /**
     * Cart lines with their product loaded, oldest-added first.
     *
     * @return Collection<int, array{cartItemId: int, product: Product, quantity: int}>
     */
    public function lines(?User $user): Collection
    {
        $cart = $this->cartFor($user);

        if ($cart === null) {
            return collect();
        }

        return $cart->items()
            ->with(['product.images', 'product.vendor:id,business_name', 'product.category:id,name,slug'])
            ->orderBy('id')
            ->get()
            // A product deleted out from under the cart leaves a dangling
            // line; drop it rather than blowing up the page.
            ->filter(fn (CartItem $item) => $item->product !== null)
            ->map(fn (CartItem $item) => [
                'cartItemId' => $item->id,
                'product' => $item->product,
                'quantity' => $item->quantity,
            ])
            ->values();
    }

    /**
     * A single-product basket for "Buy now", in the same shape as lines() so
     * checkout, plans and the card flow all consume it unchanged.
     *
     * cartItemId is null because nothing is saved to the cart: the shopper is
     * buying this one item outright, and whatever they already had stays put.
     *
     * @return Collection<int, array{cartItemId: null, product: Product, quantity: int}>
     */
    public function buyNowLines(Product $product, int $quantity): Collection
    {
        $product->loadMissing(['images', 'vendor:id,business_name', 'category:id,name,slug']);

        return collect([[
            'cartItemId' => null,
            'product' => $product,
            'quantity' => max(1, $quantity),
        ]]);
    }

    /** Empty the cart — after checkout, or once a goal has taken it over. */
    public function clear(?User $user): void
    {
        $this->cartFor($user)?->items()->delete();
    }

    /** Total units in the cart — what the header badge counts. */
    public function count(?User $user): int
    {
        $cart = $this->cartFor($user);

        return (int) ($cart?->items()->sum('quantity') ?? 0);
    }

    /**
     * Fold whatever the shopper collected before signing in into their real
     * cart, then discard the guest cart and its cookie. Quantities add up,
     * and anything that has sold out or been delisted in the meantime is
     * capped or dropped rather than blocking the login.
     *
     * @return int how many products were carried over
     */
    public function mergeGuestCartInto(User $user): int
    {
        $guestCart = $this->guestCart->cart();

        if ($guestCart === null) {
            return 0;
        }

        $merged = 0;

        foreach ($guestCart->items()->with('product')->get() as $item) {
            $product = $item->product;

            if ($product === null) {
                continue;
            }

            $wanted = $this->quantityOf($user, $product) + $item->quantity;

            try {
                $this->setQuantity($user, $product, min($wanted, $product->stock_quantity));
                $merged++;
            } catch (ValidationException) {
                // Sold out or no longer Approved — nothing to carry over.
            }
        }

        $guestCart->delete();
        $this->guestCart->forget();

        return $merged;
    }

    private function assertPurchasable(Product $product, int $quantity): void
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be at least 1.']);
        }

        if ($product->status !== ProductStatus::Approved) {
            throw ValidationException::withMessages(['product' => 'This product is not available.']);
        }

        if ($quantity > $product->stock_quantity) {
            throw ValidationException::withMessages(['quantity' => 'Only '.$product->stock_quantity.' left in stock.']);
        }
    }
}
