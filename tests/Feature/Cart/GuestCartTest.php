<?php

use App\Models\User;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Services\GuestCart;
use App\Modules\Catalog\Models\Product;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;

/**
 * Shoppers fill a cart before they have an account — the sign-in gate sits
 * at checkout, not at add-to-cart. A guest's cart is a real carts row found
 * by the long-lived cookie token GuestCart issues (not the PHP session,
 * which expires after two hours idle), and MergeGuestCartOnLogin folds it
 * into their account cart on the way in.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function guestCustomer(): User
{
    $user = User::factory()->create(['password' => bcrypt('password')]);
    $user->assignRole('Customer');

    return $user;
}

/**
 * Laravel's test client does not carry Set-Cookie into the next request the
 * way a browser does, so anything cookie-backed has to be threaded through
 * by hand.
 *
 * Both ends deal in the plaintext token: withCookie() encrypts what it is
 * given (prepareCookiesForRequest), so handing it the raw encrypted value
 * off the response would encrypt it twice and the middleware would decrypt
 * it to null.
 */
function keepCartCookie(TestResponse $response): TestResponse
{
    $cookie = $response->getCookie(GuestCart::COOKIE);

    if ($cookie !== null && $cookie->getValue() !== '') {
        test()->withCookie(GuestCart::COOKIE, $cookie->getValue());
    }

    return $response;
}

/** Add to the cart as a guest, keeping the cart cookie for what follows. */
function guestAdd(Product $product, int $quantity = 1): TestResponse
{
    return keepCartCookie(
        test()->post(route('cart.items.store'), [
            'product_uuid' => $product->uuid,
            'quantity' => $quantity,
        ]),
    );
}

function currentGuestCart(): ?Cart
{
    return Cart::query()->whereNull('user_id')->latest('id')->first();
}

it('lets a guest add to the cart without signing in', function () {
    $product = Product::factory()->approved()->create(['stock_quantity' => 5]);

    guestAdd($product, 2)
        ->assertRedirect()
        // No flash: the storefront raises its own toast naming the product,
        // and flashing here too showed the shopper two for one click.
        ->assertSessionMissing('success')
        // Persisted server-side against a cookie, not held in the session.
        ->assertCookie(GuestCart::COOKIE);

    $cart = currentGuestCart();

    expect($cart)->not->toBeNull()
        ->and($cart->user_id)->toBeNull()
        ->and($cart->items()->where('product_id', $product->id)->value('quantity'))->toBe(2);
});

it('keeps a guest cart alive after the session is gone', function () {
    $product = Product::factory()->approved()->create(['stock_quantity' => 5]);

    guestAdd($product);

    // Throw away everything about the visitor except the cart cookie —
    // which is what an expired session looks like the next day.
    $this->flushSession();

    $this->get(route('cart.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('items', 1));
});

it('shows a guest their own cart page with the items on it', function () {
    $product = Product::factory()->approved()->create(['stock_quantity' => 5, 'price_kobo' => 20_000_00]);

    guestAdd($product, 2);

    $this->get(route('cart.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Cart/Index')
            ->has('items', 1)
            ->where('items.0.productName', $product->name)
            ->where('summary.subtotalKobo', 40_000_00));
});

it('lets a guest change quantity and remove without signing in', function () {
    $product = Product::factory()->approved()->create(['stock_quantity' => 9]);

    guestAdd($product);
    $this->patch(route('cart.items.update', $product->uuid), ['quantity' => 4]);

    expect(currentGuestCart()->items()->value('quantity'))->toBe(4);

    $this->delete(route('cart.items.destroy', $product->uuid));

    expect(currentGuestCart()->items()->count())->toBe(0);
});

it('enforces stock on a guest cart just like a signed-in one', function () {
    $product = Product::factory()->approved()->create(['stock_quantity' => 2]);

    guestAdd($product, 3)->assertSessionHasErrors('quantity');

    expect(CartItem::query()->count())->toBe(0);
});

it('writes no cart row and issues no cookie for a visitor who only browses', function () {
    Product::factory()->approved()->create();

    $this->get(route('catalog.index'))->assertOk()->assertCookieMissing(GuestCart::COOKIE);
    $this->get(route('cart.index'))->assertOk()->assertCookieMissing(GuestCart::COOKIE);

    expect(Cart::query()->count())->toBe(0);
});

it('merges the guest cart into the account cart on login, adding to what is already there', function () {
    $user = guestCustomer();
    $alreadyInCart = Product::factory()->approved()->create(['stock_quantity' => 10]);
    $addedAsGuest = Product::factory()->approved()->create(['stock_quantity' => 10]);

    // Two units added earlier while signed in...
    $this->actingAs($user)->post(route('cart.items.store'), ['product_uuid' => $alreadyInCart->uuid, 'quantity' => 2]);
    $this->post(route('logout'));

    // ...then three more of the same plus something new, as a guest.
    guestAdd($alreadyInCart, 3);
    guestAdd($addedAsGuest);

    $this->post(route('login'), ['identifier' => $user->email, 'password' => 'password']);

    $cart = Cart::query()->where('user_id', $user->id)->firstOrFail();

    expect($cart->items()->where('product_id', $alreadyInCart->id)->value('quantity'))->toBe(5)
        ->and($cart->items()->where('product_id', $addedAsGuest->id)->value('quantity'))->toBe(1)
        // The guest row is consumed, not left behind to be merged twice.
        ->and(Cart::query()->whereNull('user_id')->count())->toBe(0);
});

it('caps a merge at available stock rather than failing the login', function () {
    $user = guestCustomer();
    $product = Product::factory()->approved()->create(['stock_quantity' => 3]);

    guestAdd($product, 3);

    // Sells down to one while the guest is signing in.
    $product->forceFill(['stock_quantity' => 1])->save();

    $this->post(route('login'), ['identifier' => $user->email, 'password' => 'password'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);

    $cart = Cart::query()->where('user_id', $user->id)->firstOrFail();
    expect($cart->items()->where('product_id', $product->id)->value('quantity'))->toBe(1);
});

it('sends a guest to sign in rather than to checkout', function () {
    $product = Product::factory()->approved()->create(['stock_quantity' => 5]);
    guestAdd($product);

    $this->get(route('cart.checkout'))->assertRedirect(route('login'));
    $this->post(route('cart.checkout.store'), [
        'recipient_name' => 'Yakubu Dauda', 'recipient_phone' => '08031234567',
        'delivery_address' => '12 Marina Road',
        'state' => 'Lagos',
        'lga' => 'Eti-Osa',
        'payment_method' => 'savings',
    ])->assertRedirect(route('login'));
});
