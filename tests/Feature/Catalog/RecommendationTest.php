<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\RecommendationService;
use App\Modules\Customer\Models\Wishlist;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Phase 2C recommendations.
 *
 * Deterministic rules over the customer's own wishlist, plans and orders — no
 * model, and nothing leaves the platform. Two properties are worth holding on
 * to: every suggestion states why it was made, and the site never suggests
 * something the shopper already has.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->recommendations = app(RecommendationService::class);
    $this->customer = User::factory()->create();
});

it('suggests from a category the customer has saved into', function () {
    $phones = Category::factory()->create(['name' => 'Phones']);
    $saved = Product::factory()->approved()->create([
        'category_id' => $phones->id,
        'price_kobo' => 100_000,
        'stock_quantity' => 5,
    ]);
    $another = Product::factory()->approved()->create([
        'category_id' => $phones->id,
        'price_kobo' => 110_000,
        'stock_quantity' => 5,
    ]);

    Wishlist::query()->create(['user_id' => $this->customer->id, 'product_id' => $saved->id]);

    $picks = $this->recommendations->forUser($this->customer);

    expect($picks->pluck('product.id'))->toContain($another->id);
});

it('never suggests something already wishlisted', function () {
    $category = Category::factory()->create();
    $saved = Product::factory()->approved()->create([
        'category_id' => $category->id,
        'stock_quantity' => 5,
    ]);

    Wishlist::query()->create(['user_id' => $this->customer->id, 'product_id' => $saved->id]);

    // Suggesting back what they just saved is the fastest way to look like
    // nobody is paying attention.
    expect($this->recommendations->forUser($this->customer)->pluck('product.id'))
        ->not->toContain($saved->id);
});

it('never suggests something out of stock', function () {
    $category = Category::factory()->create();
    $saved = Product::factory()->approved()->create([
        'category_id' => $category->id,
        'stock_quantity' => 5,
    ]);
    $soldOut = Product::factory()->approved()->create([
        'category_id' => $category->id,
        'stock_quantity' => 0,
    ]);

    Wishlist::query()->create(['user_id' => $this->customer->id, 'product_id' => $saved->id]);

    expect($this->recommendations->forUser($this->customer)->pluck('product.id'))
        ->not->toContain($soldOut->id);
});

it('never suggests an unapproved product', function () {
    $category = Category::factory()->create();
    $saved = Product::factory()->approved()->create(['category_id' => $category->id, 'stock_quantity' => 5]);
    $pending = Product::factory()->pending()->create(['category_id' => $category->id, 'stock_quantity' => 5]);

    Wishlist::query()->create(['user_id' => $this->customer->id, 'product_id' => $saved->id]);

    expect($this->recommendations->forUser($this->customer)->pluck('product.id'))
        ->not->toContain($pending->id);
});

it('gives every suggestion a reason the shopper can check', function () {
    $category = Category::factory()->create(['name' => 'Solar Equipment']);
    $saved = Product::factory()->approved()->create(['category_id' => $category->id, 'stock_quantity' => 5]);
    Product::factory()->approved()->create(['category_id' => $category->id, 'stock_quantity' => 5]);

    Wishlist::query()->create(['user_id' => $this->customer->id, 'product_id' => $saved->id]);

    $picks = $this->recommendations->forUser($this->customer);

    // "Recommended for you" is not a reason; the category is.
    expect($picks)->not->toBeEmpty()
        ->and($picks->first()['reason'])->not->toBe('')
        ->and($picks->every(fn ($pick) => $pick['reasonKey'] !== ''))->toBeTrue();
});

it('falls back to popular products for a brand new customer', function () {
    Category::factory()->create();
    Product::factory()->approved()->count(3)->create(['stock_quantity' => 5]);

    $picks = $this->recommendations->forUser(User::factory()->create());

    // Better than an empty shelf, and honest about what it is.
    expect($picks)->not->toBeEmpty()
        ->and($picks->first()['reasonKey'])->toBe(RecommendationService::REASON_POPULAR);
});

it('serves guests without needing an account', function () {
    Product::factory()->approved()->count(3)->create(['stock_quantity' => 5]);

    expect($this->recommendations->forUser(null))->not->toBeEmpty();
});

it('respects the limit it is given', function () {
    Product::factory()->approved()->count(10)->create(['stock_quantity' => 5]);

    expect($this->recommendations->forUser($this->customer, 3))->toHaveCount(3);
});
