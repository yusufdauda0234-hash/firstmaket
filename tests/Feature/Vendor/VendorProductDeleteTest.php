<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * A vendor may remove their own listing, but not one a customer is already
 * mid-transaction on — deleting that would pull the item out from under an
 * open order or a plan somebody is still paying off.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->product = Product::factory()->approved()->create(['price_kobo' => 20_000_00]);
    $this->vendor = $this->product->vendor;
    $this->vendor->forceFill(['status' => VendorStatus::Approved])->save();
    $this->vendorUser = $this->vendor->user;
    $this->vendorUser->assignRole('Vendor');

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);
});

function deleteListingUrl(string $uuid): string
{
    return 'http://'.strtolower((string) config('app.vendor_domain')).'/products/'.$uuid;
}

it('soft deletes an untouched listing', function () {
    $draft = Product::factory()->create([
        'vendor_id' => $this->vendor->id,
        'status' => ProductStatus::Draft,
    ]);

    $this->actingAs($this->vendorUser)
        ->delete(deleteListingUrl($draft->uuid))
        ->assertRedirect()
        ->assertSessionHas('success');

    // Soft, so the slug stays taken and existing references still resolve.
    expect(Product::query()->find($draft->id))->toBeNull()
        ->and(Product::withTrashed()->find($draft->id))->not->toBeNull();
});

it('refuses to delete a listing with an order still in flight', function () {
    $order = testOrder($this->customer, $this->product);
    $order->forceFill(['status' => OrderStatus::Processing])->save();

    $this->actingAs($this->vendorUser)
        ->delete(deleteListingUrl($this->product->uuid))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(Product::query()->find($this->product->id))->not->toBeNull();
});

it('allows deletion once every order has settled', function () {
    $order = testOrder($this->customer, $this->product);
    $order->forceFill(['status' => OrderStatus::Delivered])->save();

    $this->actingAs($this->vendorUser)
        ->delete(deleteListingUrl($this->product->uuid))
        ->assertSessionHas('success');

    expect(Product::query()->find($this->product->id))->toBeNull();
});

it('refuses to delete a listing somebody is still saving towards', function () {
    // A plan part-paid, so still open.
    testPlan($this->customer, $this->product);

    $this->actingAs($this->vendorUser)
        ->delete(deleteListingUrl($this->product->uuid))
        ->assertSessionHas('error');

    expect(Product::query()->find($this->product->id))->not->toBeNull();
});

it('will not delete another vendor listing', function () {
    $theirs = Product::factory()->create(['status' => ProductStatus::Draft]);

    $this->actingAs($this->vendorUser)
        ->delete(deleteListingUrl($theirs->uuid))
        ->assertForbidden();

    expect(Product::query()->find($theirs->id))->not->toBeNull();
});

it('flags on the index which listings can be deleted', function () {
    $free = Product::factory()->create([
        'vendor_id' => $this->vendor->id,
        'status' => ProductStatus::Draft,
    ]);

    $order = testOrder($this->customer, $this->product);
    $order->forceFill(['status' => OrderStatus::Processing])->save();

    $response = $this->actingAs($this->vendorUser)
        ->get('http://'.strtolower((string) config('app.vendor_domain')).'/products')
        ->assertOk();

    $rows = collect($response->viewData('page')['props']['products']['data'])
        ->keyBy('uuid');

    expect($rows[$free->uuid]['canDelete'])->toBeTrue()
        ->and($rows[$this->product->uuid]['canDelete'])->toBeFalse();
});

it('only offers a marketplace link once the listing is approved', function () {
    $draft = Product::factory()->create([
        'vendor_id' => $this->vendor->id,
        'status' => ProductStatus::Draft,
    ]);

    $response = $this->actingAs($this->vendorUser)
        ->get('http://'.strtolower((string) config('app.vendor_domain')).'/products')
        ->assertOk();

    $rows = collect($response->viewData('page')['props']['products']['data'])
        ->keyBy('uuid');

    // And the link must point at the storefront, not the vendor subdomain
    // this page is served from.
    expect($rows[$draft->uuid]['viewUrl'])->toBeNull()
        ->and($rows[$this->product->uuid]['viewUrl'])
        ->toStartWith(rtrim((string) config('app.url'), '/').'/product/');
});
