<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Sprint 3 QA: vendor suspension delists approved products
 * (docs/firstmarket_Implementation_Plan.md). Suspension travels through the
 * VendorSuspended domain event; the Catalog module reacts by delisting.
 */
beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function suspensionAdminUrl(string $path): string
{
    return 'http://'.config('app.admin_domain').'/vendors/'.ltrim($path, '/');
}

function suspensionStaff(string $role): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->assignRole($role);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user;
}

function approvedVendorWithProducts(): VendorProfile
{
    $vendorUser = User::factory()->create(['user_type' => UserType::Vendor]);
    $vendorUser->assignRole('Vendor');

    $vendor = VendorProfile::query()->create([
        'user_id' => $vendorUser->id,
        'business_name' => 'Suspendable Stores Ltd',
        'contact_name' => 'Sus Pendable',
        'status' => VendorStatus::Approved,
        'approved_at' => now(),
    ]);

    $category = Category::factory()->create();

    Product::factory()->approved()->count(2)->create([
        'vendor_id' => $vendor->id,
        'category_id' => $category->id,
    ]);
    Product::factory()->create([
        'vendor_id' => $vendor->id,
        'category_id' => $category->id,
        'status' => ProductStatus::Draft,
    ]);

    return $vendor;
}

it('suspends an approved vendor and delists their approved products', function () {
    $vendor = approvedVendorWithProducts();
    $admin = suspensionStaff('Administrator');

    $this->actingAs($admin)
        ->post(suspensionAdminUrl($vendor->uuid.'/suspend'), ['reason' => 'Counterfeit goods reported.'])
        ->assertRedirect();

    $vendor->refresh();

    expect($vendor->status)->toBe(VendorStatus::Suspended)
        ->and(Product::query()->where('vendor_id', $vendor->id)->where('status', ProductStatus::Approved)->count())->toBe(0)
        ->and(Product::query()->where('vendor_id', $vendor->id)->where('status', ProductStatus::Delisted)->count())->toBe(2)
        // Drafts are untouched — only live listings come down.
        ->and(Product::query()->where('vendor_id', $vendor->id)->where('status', ProductStatus::Draft)->count())->toBe(1);

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $admin->id,
        'action' => 'vendor.suspended',
    ]);
});

it('requires a reason to suspend', function () {
    $vendor = approvedVendorWithProducts();

    $this->actingAs(suspensionStaff('Administrator'))
        ->post(suspensionAdminUrl($vendor->uuid.'/suspend'), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($vendor->refresh()->status)->toBe(VendorStatus::Approved);
});

it('denies suspension to a Support Agent', function () {
    $vendor = approvedVendorWithProducts();

    $this->actingAs(suspensionStaff('Support Agent'))
        ->post(suspensionAdminUrl($vendor->uuid.'/suspend'), ['reason' => 'Trying anyway.'])
        ->assertForbidden();

    expect($vendor->refresh()->status)->toBe(VendorStatus::Approved);
});

it('cannot suspend a vendor who is not approved', function () {
    $vendorUser = User::factory()->create(['user_type' => UserType::Vendor]);
    $vendor = VendorProfile::query()->create([
        'user_id' => $vendorUser->id,
        'business_name' => 'Still Pending Ltd',
        'contact_name' => 'Pen Ding',
        'status' => VendorStatus::Pending,
    ]);

    $this->actingAs(suspensionStaff('Administrator'))
        ->post(suspensionAdminUrl($vendor->uuid.'/suspend'), ['reason' => 'Too early.'])
        ->assertSessionHasErrors('status');

    expect($vendor->refresh()->status)->toBe(VendorStatus::Pending);
});

it('reinstates a suspended vendor without relisting products', function () {
    $vendor = approvedVendorWithProducts();
    $admin = suspensionStaff('Administrator');

    $this->actingAs($admin)->post(suspensionAdminUrl($vendor->uuid.'/suspend'), ['reason' => 'Investigation.']);

    $this->actingAs($admin)
        ->post(suspensionAdminUrl($vendor->uuid.'/reinstate'))
        ->assertRedirect();

    $vendor->refresh();

    expect($vendor->status)->toBe(VendorStatus::Approved)
        ->and($vendor->rejection_reason)->toBeNull()
        // Delisted products stay delisted until the vendor resubmits.
        ->and(Product::query()->where('vendor_id', $vendor->id)->where('status', ProductStatus::Delisted)->count())->toBe(2);

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $admin->id,
        'action' => 'vendor.reinstated',
    ]);
});
