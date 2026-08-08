<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * The bulk bars behind the checkboxes on the vendor Products and Orders
 * tables. Both endpoints are scoped to the signed-in vendor's own rows and
 * both skip a row that is at the wrong stage rather than failing the batch.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->product = Product::factory()->approved()->create(['price_kobo' => 20_000_00]);
    $this->vendor = $this->product->vendor;
    // ->approved() approves the listing; the profile behind it still starts
    // Pending, and only an approved vendor may manage listings.
    $this->vendor->forceFill(['status' => VendorStatus::Approved])->save();
    $this->vendorUser = $this->vendor->user;
    $this->vendorUser->assignRole('Vendor');

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);
});

/** A URL on the Vendor Center subdomain, where these routes live. */
function vendorBulkUrl(string $path): string
{
    return 'http://'.strtolower((string) config('app.vendor_domain')).'/'.ltrim($path, '/');
}

function bulkTestListing(int $vendorId, ProductStatus $status = ProductStatus::Draft): Product
{
    return Product::factory()->create(['vendor_id' => $vendorId, 'status' => $status]);
}

// ── Products: bulk submit for approval ──────────────────────────────────

it('submits several drafts for approval at once', function () {
    $drafts = collect([bulkTestListing($this->vendor->id), bulkTestListing($this->vendor->id)]);

    $this->actingAs($this->vendorUser)
        ->post(vendorBulkUrl('products/bulk-submit'), ['uuids' => $drafts->pluck('uuid')->all()])
        ->assertRedirect()
        ->assertSessionHas('success');

    foreach ($drafts as $draft) {
        expect($draft->fresh()->status)->toBe(ProductStatus::PendingApproval);
    }
});

it('skips a listing that is not a draft instead of failing the batch', function () {
    $draft = bulkTestListing($this->vendor->id);
    $live = bulkTestListing($this->vendor->id, ProductStatus::Approved);

    $this->actingAs($this->vendorUser)
        ->post(vendorBulkUrl('products/bulk-submit'), ['uuids' => [$draft->uuid, $live->uuid]])
        ->assertRedirect();

    expect($draft->fresh()->status)->toBe(ProductStatus::PendingApproval)
        ->and($live->fresh()->status)->toBe(ProductStatus::Approved);
});

it('will not submit another vendor listing', function () {
    $theirs = Product::factory()->create(['status' => ProductStatus::Draft]);

    $this->actingAs($this->vendorUser)
        ->post(vendorBulkUrl('products/bulk-submit'), ['uuids' => [$theirs->uuid]])
        ->assertRedirect();

    // Scoped to the vendor's own products, so a forged uuid matches nothing.
    expect($theirs->fresh()->status)->toBe(ProductStatus::Draft);
});

it('requires at least one listing', function () {
    $this->actingAs($this->vendorUser)
        ->post(vendorBulkUrl('products/bulk-submit'), ['uuids' => []])
        ->assertSessionHasErrors('uuids');
});

it('reaches the bulk route rather than binding "bulk-submit" as a product', function () {
    // Registered before products/{product:uuid}: a 404 here means the
    // update route swallowed it and model binding failed.
    $this->actingAs($this->vendorUser)
        ->post(vendorBulkUrl('products/bulk-submit'), ['uuids' => []])
        ->assertSessionHasErrors('uuids');
});

it('does not serve the vendor bulk route on the storefront', function () {
    $draft = bulkTestListing($this->vendor->id);

    $this->actingAs($this->vendorUser)
        ->post('http://firstmaket.localhost/products/bulk-submit', ['uuids' => [$draft->uuid]])
        ->assertNotFound();

    expect($draft->fresh()->status)->toBe(ProductStatus::Draft);
});

// ── Orders: bulk mark ready for pickup ──────────────────────────────────

it('marks several processing orders ready at once', function () {
    $orders = collect([
        testOrder($this->customer, $this->product),
        testOrder($this->customer, $this->product),
    ]);

    foreach ($orders as $order) {
        $order->forceFill(['status' => OrderStatus::Processing])->save();
    }

    $this->actingAs($this->vendorUser)
        ->post(vendorBulkUrl('orders/bulk-ready'), ['uuids' => $orders->pluck('uuid')->all()])
        ->assertRedirect()
        ->assertSessionHas('success');

    foreach ($orders as $order) {
        expect($order->fresh()->status)->toBe(OrderStatus::ReadyForPickup);
    }
});

it('skips an order that is not yet processing', function () {
    $ready = testOrder($this->customer, $this->product);
    $ready->forceFill(['status' => OrderStatus::Processing])->save();

    $pending = testOrder($this->customer, $this->product);

    $this->actingAs($this->vendorUser)
        ->post(vendorBulkUrl('orders/bulk-ready'), ['uuids' => [$ready->uuid, $pending->uuid]])
        ->assertRedirect();

    expect($ready->fresh()->status)->toBe(OrderStatus::ReadyForPickup)
        ->and($pending->fresh()->status)->toBe(OrderStatus::Pending);
});

it('will not mark another vendor order ready', function () {
    $otherProduct = Product::factory()->approved()->create(['price_kobo' => 5_000_00]);
    $otherProduct->vendor->user->assignRole('Vendor');

    $theirs = testOrder($this->customer, $otherProduct);
    $theirs->forceFill(['status' => OrderStatus::Processing])->save();

    $this->actingAs($this->vendorUser)
        ->post(vendorBulkUrl('orders/bulk-ready'), ['uuids' => [$theirs->uuid]])
        ->assertRedirect();

    expect($theirs->fresh()->status)->toBe(OrderStatus::Processing);
});

it('caps an order batch at 100', function () {
    $uuids = collect(range(1, 101))->map(fn () => Str::uuid()->toString())->all();

    $this->actingAs($this->vendorUser)
        ->post(vendorBulkUrl('orders/bulk-ready'), ['uuids' => $uuids])
        ->assertSessionHasErrors('uuids');
});
