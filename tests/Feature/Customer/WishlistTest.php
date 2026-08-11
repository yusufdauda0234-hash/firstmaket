<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\Wishlist;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use App\Modules\Customer\Models\WishlistPriceAlert;
use App\Modules\Customer\Notifications\WishlistPriceDropNotification;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->customer = User::factory()->create();
    $this->otherCustomer = User::factory()->create();
    $this->product = Product::factory()->approved()->create();
});

it('requires authentication for wishlist actions', function () {
    $this->get('/account/wishlist')->assertRedirect();
    $this->post("/account/wishlist/{$this->product->uuid}")->assertRedirect();
});

it('saves an approved product once and lists it for the owner', function () {
    $this->actingAs($this->customer)
        ->post("/account/wishlist/{$this->product->uuid}")
        ->assertRedirect();

    $this->actingAs($this->customer)
        ->post("/account/wishlist/{$this->product->uuid}")
        ->assertRedirect();

    expect(Wishlist::query()->where('user_id', $this->customer->id)->count())->toBe(1);

    $this->actingAs($this->customer)
        ->get('/account/wishlist')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Account/Wishlist')
            ->has('products', 1)
            ->where('products.0.uuid', $this->product->uuid));
});

it('keeps wishlist items private and only lets the owner remove them', function () {
    Wishlist::query()->create([
        'user_id' => $this->customer->id,
        'product_id' => $this->product->id,
    ]);

    $this->actingAs($this->otherCustomer)
        ->get('/account/wishlist')
        ->assertInertia(fn (Assert $page) => $page->component('Account/Wishlist')->has('products', 0));

    $this->actingAs($this->otherCustomer)
        ->delete("/account/wishlist/{$this->product->uuid}")
        ->assertRedirect();

    expect(Wishlist::query()->where('user_id', $this->customer->id)->exists())->toBeTrue();

    $this->actingAs($this->customer)
        ->delete("/account/wishlist/{$this->product->uuid}")
        ->assertRedirect();

    expect(Wishlist::query()->exists())->toBeFalse();
});

it('does not save an unpublished product', function () {
    $product = Product::factory()->create();

    $this->actingAs($this->customer)
        ->post("/account/wishlist/{$product->uuid}")
        ->assertNotFound();

    expect(Wishlist::query()->count())->toBe(0);
});

it('lets the owner configure a price-drop threshold for a saved product', function () {
    Wishlist::query()->create([
        'user_id' => $this->customer->id,
        'product_id' => $this->product->id,
    ]);

    $this->actingAs($this->customer)
        ->put("/account/wishlist/{$this->product->uuid}/price-alert", ['threshold_percent' => 20])
        ->assertRedirect();

    expect(WishlistPriceAlert::query()->first())
        ->threshold_percent->toBe(20);
});

it('does not let another customer configure a price alert', function () {
    Wishlist::query()->create([
        'user_id' => $this->customer->id,
        'product_id' => $this->product->id,
    ]);

    $this->actingAs($this->otherCustomer)
        ->put("/account/wishlist/{$this->product->uuid}/price-alert", ['threshold_percent' => 5])
        ->assertNotFound();

    expect(WishlistPriceAlert::query()->count())->toBe(0);
});

it('builds a price-drop notification with the product link and discount', function () {
    $notification = new WishlistPriceDropNotification($this->product, 100_00, 80_00);
    $data = $notification->toDatabase($this->customer)->data;

    expect($data['title'])->toBe('A saved item just dropped in price')
        ->and($data['body'])->toContain('20% cheaper')
        ->and($data['url'])->toContain('/product/'.$this->product->slug);
});
/*
 * The heart on a product card is drawn from a shared list of saved uuids, so
 * that every surface the card appears on (home, catalogue, search, cart
 * recommendations) shows the same state without each one joining the
 * wishlist itself.
 */
it('shares the saved product uuids with every customer-facing page', function () {
    Wishlist::query()->create([
        'user_id' => $this->customer->id,
        'product_id' => $this->product->id,
    ]);

    $this->actingAs($this->customer)
        ->get('/catalog')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('wishlistUuids', 1)
            ->where('wishlistUuids.0', $this->product->uuid));
});

it('does not share one customer saved items with anybody else', function () {
    Wishlist::query()->create([
        'user_id' => $this->customer->id,
        'product_id' => $this->product->id,
    ]);

    $this->actingAs($this->otherCustomer)
        ->get('/catalog')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('wishlistUuids', 0));
});

it('shares an empty saved list for a guest', function () {
    $this->get('/catalog')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('wishlistUuids', 0));
});
