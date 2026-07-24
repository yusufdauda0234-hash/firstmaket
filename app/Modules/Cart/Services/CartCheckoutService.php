<?php

namespace App\Modules\Cart\Services;

use App\Models\User;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\WalletTransactionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 8 cart pay-in-full checkout
 * (docs/FirstMaket_Implementation_Plan.md Sprint 8). The delivery address is
 * collected upfront on the checkout screen — resolving the design question
 * that blocked this sprint — before a single wallet debit for the whole
 * cart total. Every item is re-validated (Approved + in stock) at this
 * moment, not just at add-to-cart; an item that fails stays in the cart with
 * a clear error, and the wallet is only ever debited for what actually gets
 * purchased. Order creation itself is delegated to OrderService, which owns
 * every order state change.
 */
class CartCheckoutService
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly OrderService $orderService,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    /**
     * @return array{session: CheckoutSession, skippedProductNames: array<int, string>}
     */
    public function payInFull(User $user, Cart $cart, string $deliveryAddress, string $state, string $lga): array
    {
        return DB::transaction(function () use ($user, $cart, $deliveryAddress, $state, $lga) {
            $cartItems = CartItem::query()
                ->where('cart_id', $cart->id)
                ->with('product')
                ->lockForUpdate()
                ->get();

            if ($cartItems->isEmpty()) {
                throw ValidationException::withMessages(['cart' => 'Your cart is empty.']);
            }

            /** @var array<int, array{product: Product, quantity: int}> $purchasable */
            $purchasable = [];
            $purchasedCartItemIds = [];
            $skipped = [];

            foreach ($cartItems as $item) {
                $product = Product::query()->whereKey($item->product_id)->lockForUpdate()->first();

                if ($product === null
                    || $product->status !== ProductStatus::Approved
                    || $item->quantity > $product->stock_quantity
                ) {
                    $skipped[] = $item->product->name;

                    continue;
                }

                $purchasable[] = ['product' => $product, 'quantity' => $item->quantity];
                $purchasedCartItemIds[] = $item->id;
            }

            if ($purchasable === []) {
                throw ValidationException::withMessages([
                    'cart' => 'None of your cart items are available right now: '.implode(', ', $skipped).'.',
                ]);
            }

            $totalKobo = 0;
            foreach ($purchasable as $item) {
                $totalKobo += $item['product']->price_kobo * $item['quantity'];
            }

            $walletTransaction = $this->walletService->debitForSavings(
                user: $user,
                amountKobo: $totalKobo,
                type: WalletTransactionType::CartCheckout,
                reference: 'CART-'.Str::uuid()->toString(),
                metadata: ['item_count' => count($purchasable)],
            );

            $session = CheckoutSession::query()->create([
                'user_id' => $user->id,
                'wallet_transaction_id' => $walletTransaction->id,
                'total_amount_kobo' => $totalKobo,
                'delivery_address' => $deliveryAddress,
                'state' => $state,
                'lga' => $lga,
                'created_at' => now(),
            ]);

            $this->orderService->createFromCheckoutSession($user, $session, $purchasable);

            // Only the items that were actually purchased leave the cart —
            // a skipped (now unavailable) item stays with its error surfaced.
            CartItem::query()->whereIn('id', $purchasedCartItemIds)->delete();

            $this->auditLogger->log(
                actor: $user,
                subject: $session,
                action: 'cart.checkout_paid_in_full',
                newValues: [
                    'total_amount_kobo' => $totalKobo,
                    'item_count' => count($purchasable),
                    'skipped_count' => count($skipped),
                ],
            );

            return ['session' => $session, 'skippedProductNames' => $skipped];
        });
    }
}
