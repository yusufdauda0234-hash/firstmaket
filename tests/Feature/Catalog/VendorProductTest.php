<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Sprint 3: vendor listing management.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->category = Category::factory()->create();
});

function approvedVendor(): User
{
    $profile = VendorProfile::factory()->approved()->create();
    $user = $profile->user;
    $user->assignRole('Vendor');

    return $user;
}

it('lets an approved vendor create a draft product priced in kobo', function () {
    $vendor = approvedVendor();

    $this->actingAs($vendor)->post(route('vendor.products.store'), [
        'category_id' => $this->category->id,
        'name' => 'Solar Panel 300W',
        'description' => 'A reliable 300W panel.',
        'price_naira' => 150000.50,
        'stock_quantity' => 5,
    ])->assertRedirect(route('vendor.products.index'));

    $product = Product::query()->firstOrFail();

    expect($product->status)->toBe(ProductStatus::Draft)
        ->and($product->price_kobo)->toBe(15000050)
        ->and($product->vendor_id)->toBe($vendor->vendorProfile->id);
});

it('lets a vendor submit a draft for approval and records the fee and status event', function () {
    $vendor = approvedVendor();
    $product = Product::factory()->create([
        'vendor_id' => $vendor->vendorProfile->id,
        'category_id' => $this->category->id,
    ]);

    $this->actingAs($vendor)->post(route('vendor.products.submit', ['product' => $product->uuid]))
        ->assertRedirect();

    $product->refresh();

    expect($product->status)->toBe(ProductStatus::PendingApproval)
        ->and($product->submitted_at)->not->toBeNull();

    $this->assertDatabaseHas('product_status_events', [
        'product_id' => $product->id,
        'old_status' => 'draft',
        'new_status' => 'pending_approval',
    ]);
    $this->assertDatabaseHas('product_posting_fees', [
        'product_id' => $product->id,
        'payment_status' => 'not_required',
    ]);
});

it('blocks unapproved vendors from creating products', function () {
    $profile = VendorProfile::factory()->create(['status' => VendorStatus::Pending]);
    $user = $profile->user;
    $user->assignRole('Vendor');

    $this->actingAs($user)->post(route('vendor.products.store'), [
        'category_id' => $this->category->id,
        'name' => 'Blocked Product',
        'description' => 'Should not exist.',
        'price_naira' => 1000,
        'stock_quantity' => 1,
    ])->assertForbidden();

    expect(Product::query()->count())->toBe(0);
});

it('blocks a vendor from editing another vendor\'s product', function () {
    $owner = approvedVendor();
    $intruder = approvedVendor();

    $product = Product::factory()->create([
        'vendor_id' => $owner->vendorProfile->id,
        'category_id' => $this->category->id,
    ]);

    $this->actingAs($intruder)
        ->get(route('vendor.products.edit', ['product' => $product->uuid]))
        ->assertForbidden();
});

it('returns an approved product to pending approval when the vendor changes the price', function () {
    $vendor = approvedVendor();
    $product = Product::factory()->approved()->create([
        'vendor_id' => $vendor->vendorProfile->id,
        'category_id' => $this->category->id,
        'price_kobo' => 10000000,
    ]);

    $this->actingAs($vendor)->post(route('vendor.products.update', ['product' => $product->uuid]), [
        'category_id' => $this->category->id,
        'name' => $product->name,
        'description' => $product->description,
        'price_naira' => 120000,
        'stock_quantity' => $product->stock_quantity,
    ])->assertRedirect(route('vendor.products.index'));

    $product->refresh();

    expect($product->status)->toBe(ProductStatus::PendingApproval)
        ->and($product->price_kobo)->toBe(12000000);

    $this->assertDatabaseHas('product_price_history', [
        'product_id' => $product->id,
        'old_price_kobo' => 10000000,
        'new_price_kobo' => 12000000,
    ]);
});

it('keeps an approved product approved when only stock changes', function () {
    $vendor = approvedVendor();
    $product = Product::factory()->approved()->create([
        'vendor_id' => $vendor->vendorProfile->id,
        'category_id' => $this->category->id,
        'price_kobo' => 10000000,
    ]);

    $this->actingAs($vendor)->post(route('vendor.products.update', ['product' => $product->uuid]), [
        'category_id' => $this->category->id,
        'name' => $product->name,
        'description' => $product->description,
        'price_naira' => 100000,
        'stock_quantity' => 99,
    ]);

    expect($product->refresh()->status)->toBe(ProductStatus::Approved)
        ->and($product->stock_quantity)->toBe(99);
});
