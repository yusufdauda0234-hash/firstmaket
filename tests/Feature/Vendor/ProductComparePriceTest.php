<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\BuiltInProductFieldSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(BuiltInProductFieldSeeder::class);

    $this->vendorUser = User::factory()->create([
        'user_type' => UserType::Vendor,
        'phone_verified_at' => now(),
    ]);
    $this->vendorUser->assignRole('Vendor');

    $this->vendorProfile = VendorProfile::factory()->create([
        'user_id' => $this->vendorUser->id,
        'status' => VendorStatus::Approved,
    ]);

    $this->category = Category::factory()->create(['is_active' => true]);
});

function comparePriceHost(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.vendor_domain')).$path;
}

function comparePricePayload(array $overrides = []): array
{
    return array_merge([
        'category_id' => test()->category->id,
        'name' => 'A CAMERA',
        'description' => 'A good one.',
        'price_naira' => 800000,
        'stock_quantity' => 3,
    ], $overrides);
}

it('saves a regular price alongside the selling price', function () {
    $this->actingAs($this->vendorUser)
        ->post(comparePriceHost('/products'), comparePricePayload(['compare_at_naira' => 950000]))
        ->assertSessionHasNoErrors();

    expect(Product::query()->first()->compare_at_price_kobo)->toBe(95_000_000);
});

it('is optional', function () {
    $this->actingAs($this->vendorUser)
        ->post(comparePriceHost('/products'), comparePricePayload())
        ->assertSessionHasNoErrors();

    expect(Product::query()->first()->compare_at_price_kobo)->toBeNull();
});

it('refuses a regular price that is not actually higher', function (int $compareAt) {
    /*
     * The product page draws a line through this number and prints a saving.
     * A "was" price at or below what is being charged is an invented
     * discount, so it is refused rather than quietly dropped at render time
     * where nobody would notice it had been ignored.
     */
    $this->actingAs($this->vendorUser)
        ->post(comparePriceHost('/products'), comparePricePayload(['compare_at_naira' => $compareAt]))
        ->assertSessionHasErrors('compare_at_naira');

    expect(Product::query()->count())->toBe(0);
})->with([
    'lower' => 700000,
    'identical' => 800000,
]);

it('says why it refused', function () {
    $this->actingAs($this->vendorUser)
        ->post(comparePriceHost('/products'), comparePricePayload(['compare_at_naira' => 700000]))
        ->assertSessionHasErrors([
            'compare_at_naira' => 'The regular price has to be higher than the price you are selling at.',
        ]);
});

it('lets a vendor drop the discount again', function () {
    $product = Product::factory()->create([
        'vendor_id' => $this->vendorProfile->id,
        'category_id' => $this->category->id,
        'compare_at_price_kobo' => 95_000_000,
    ]);

    $this->actingAs($this->vendorUser)
        ->post(comparePriceHost('/products/'.$product->uuid), comparePricePayload(['compare_at_naira' => '']))
        ->assertSessionHasNoErrors();

    expect($product->refresh()->compare_at_price_kobo)->toBeNull();
});

it('hands it back when the listing is reopened', function () {
    $product = Product::factory()->create([
        'vendor_id' => $this->vendorProfile->id,
        'category_id' => $this->category->id,
        'compare_at_price_kobo' => 95_000_000,
    ]);

    // The form posts every field on save, so a payload missing this would
    // wipe the discount whenever somebody fixed a typo elsewhere.
    $this->actingAs($this->vendorUser)
        ->getJson(comparePriceHost('/products/'.$product->uuid.'/details'))
        ->assertJsonPath('product.compareAtNaira', 950000);
});

it('offers the field on the vendor form, worded by admin', function () {
    $this->actingAs($this->vendorUser)
        ->get(comparePriceHost('/products'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('builtInFields.compare_at_naira.label', 'Regular price (₦)'));
});

// ── What a shopper gets ────────────────────────────────────────────────

it('sends the struck-through price to the product page', function () {
    $product = Product::factory()->create([
        'vendor_id' => $this->vendorProfile->id,
        'category_id' => $this->category->id,
        'price_kobo' => 80_000_000,
        'compare_at_price_kobo' => 95_000_000,
        'status' => ProductStatus::Approved,
    ]);

    $this->get('/product/'.$product->slug)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('product.priceKobo', 80_000_000)
            ->where('product.compareAtPriceKobo', 95_000_000));
});

it('sends null when there is no discount to show', function () {
    $product = Product::factory()->create([
        'vendor_id' => $this->vendorProfile->id,
        'category_id' => $this->category->id,
        'compare_at_price_kobo' => null,
        'status' => ProductStatus::Approved,
    ]);

    $this->get('/product/'.$product->slug)
        ->assertInertia(fn ($page) => $page->where('product.compareAtPriceKobo', null));
});
