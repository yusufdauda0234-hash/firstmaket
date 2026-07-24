<?php

namespace App\Modules\Cart\Services;

use App\Models\User;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Models\Product;
use App\Shared\Enums\ProductStatus;
use Illuminate\Validation\ValidationException;

/**
 * Owns cart mutations (docs/FirstMaket_Implementation_Plan.md Sprint 8).
 * Product Approved/in-stock status is checked on every mutation, not just
 * add — checkout re-validates again since a product can change state
 * between add-to-cart and checkout.
 */
class CartService
{
    /** Every customer has exactly one cart; create it lazily. */
    public function getOrCreate(User $user): Cart
    {
        return Cart::query()->firstOrCreate(['user_id' => $user->id]);
    }

    /**
     * Add a product to the cart, or increase its quantity if already
     * present.
     */
    public function addItem(User $user, Product $product, int $quantity = 1): CartItem
    {
        $this->assertPurchasable($product, $quantity);

        $cart = $this->getOrCreate($user);

        $item = CartItem::query()->firstOrNew([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
        ]);

        $item->quantity = ($item->exists ? $item->quantity : 0) + $quantity;
        $item->save();

        return $item;
    }

    public function updateQuantity(User $user, CartItem $item, int $quantity): CartItem
    {
        $this->assertOwnership($user, $item);
        $this->assertPurchasable($item->product, $quantity);

        $item->forceFill(['quantity' => $quantity])->save();

        return $item;
    }

    public function removeItem(User $user, CartItem $item): void
    {
        $this->assertOwnership($user, $item);

        $item->delete();
    }

    private function assertOwnership(User $user, CartItem $item): void
    {
        if ($item->cart->user_id !== $user->id) {
            throw ValidationException::withMessages(['item' => 'This cart item does not belong to you.']);
        }
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
