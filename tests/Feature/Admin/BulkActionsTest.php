<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

function bulkAdmin(string $role = 'Administrator'): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->assignRole($role);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user;
}

function adminHost(string $path): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/'.ltrim($path, '/');
}

function pendingProducts(int $count): Collection
{
    return Product::factory()->count($count)->create([
        'status' => ProductStatus::PendingApproval,
        'submitted_at' => now(),
    ]);
}

// ── Products ──────────────────────────────────────────────────────────────

it('approves several listings in one request', function () {
    $products = pendingProducts(3);

    $this->actingAs(bulkAdmin())
        ->post(adminHost('products/bulk'), [
            'action' => 'approve',
            'uuids' => $products->pluck('uuid')->all(),
        ])
        ->assertRedirect();

    foreach ($products as $product) {
        expect($product->fresh()->status)->toBe(ProductStatus::Approved);
    }
});

it('rejects several listings with one shared reason', function () {
    $products = pendingProducts(2);

    $this->actingAs(bulkAdmin())
        ->post(adminHost('products/bulk'), [
            'action' => 'reject',
            'uuids' => $products->pluck('uuid')->all(),
            'reason' => 'Photos do not show the actual item.',
        ])
        ->assertRedirect();

    foreach ($products as $product) {
        expect($product->fresh()->status)->toBe(ProductStatus::Rejected);
    }
});

it('refuses to bulk reject without a reason', function () {
    $products = pendingProducts(2);

    // A rejection the vendor cannot act on is worse than no rejection.
    $this->actingAs(bulkAdmin())
        ->post(adminHost('products/bulk'), [
            'action' => 'reject',
            'uuids' => $products->pluck('uuid')->all(),
        ])
        ->assertSessionHasErrors('reason');

    expect($products->first()->fresh()->status)->toBe(ProductStatus::PendingApproval);
});

it('carries on when one listing has already been actioned', function () {
    $products = pendingProducts(3);

    // A colleague approved this one a moment ago.
    $products[1]->forceFill(['status' => ProductStatus::Approved])->save();

    $this->actingAs(bulkAdmin())
        ->post(adminHost('products/bulk'), [
            'action' => 'approve',
            'uuids' => $products->pluck('uuid')->all(),
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    // The other two still went through rather than the batch aborting.
    expect($products[0]->fresh()->status)->toBe(ProductStatus::Approved)
        ->and($products[2]->fresh()->status)->toBe(ProductStatus::Approved);
});

it('requires at least one selection', function () {
    $this->actingAs(bulkAdmin())
        ->post(adminHost('products/bulk'), ['action' => 'approve', 'uuids' => []])
        ->assertSessionHasErrors('uuids');
});

it('caps a batch at 100 listings', function () {
    $uuids = collect(range(1, 101))->map(fn () => (string) Str::uuid())->all();

    $this->actingAs(bulkAdmin())
        ->post(adminHost('products/bulk'), ['action' => 'approve', 'uuids' => $uuids])
        ->assertSessionHasErrors('uuids');
});

it('rejects an unknown bulk action', function () {
    $products = pendingProducts(1);

    $this->actingAs(bulkAdmin())
        ->post(adminHost('products/bulk'), [
            'action' => 'delete_everything',
            'uuids' => $products->pluck('uuid')->all(),
        ])
        ->assertSessionHasErrors('action');
});

it('blocks bulk product actions for staff without products.approve', function () {
    $products = pendingProducts(2);

    $this->actingAs(bulkAdmin('Support Agent'))
        ->post(adminHost('products/bulk'), [
            'action' => 'approve',
            'uuids' => $products->pluck('uuid')->all(),
        ])
        ->assertForbidden();

    expect($products->first()->fresh()->status)->toBe(ProductStatus::PendingApproval);
});

it('does not treat "bulk" as a product identifier', function () {
    // products/bulk is registered before products/{product:uuid}; if the order
    // were wrong this would 404 looking for a product called "bulk".
    $this->actingAs(bulkAdmin())
        ->post(adminHost('products/bulk'), ['action' => 'approve', 'uuids' => []])
        ->assertSessionHasErrors('uuids');
});

// ── Vendors ───────────────────────────────────────────────────────────────

it('approves several vendor applications at once', function () {
    $profiles = VendorProfile::factory()->count(3)->create(['status' => VendorStatus::Pending]);

    $this->actingAs(bulkAdmin())
        ->post(adminHost('vendors/bulk'), [
            'action' => 'approve',
            'uuids' => $profiles->pluck('uuid')->all(),
        ])
        ->assertRedirect();

    foreach ($profiles as $profile) {
        $fresh = $profile->fresh();
        // approved_at is not fillable, so its presence proves the transition
        // ran through VendorApprovalService.
        expect($fresh->status)->toBe(VendorStatus::Approved)
            ->and($fresh->approved_at)->not->toBeNull();
    }
});

it('skips vendors that are no longer pending', function () {
    $profiles = VendorProfile::factory()->count(2)->create(['status' => VendorStatus::Pending]);
    $profiles[0]->forceFill(['status' => VendorStatus::Approved])->save();

    $this->actingAs(bulkAdmin())
        ->post(adminHost('vendors/bulk'), [
            'action' => 'approve',
            'uuids' => $profiles->pluck('uuid')->all(),
        ])
        ->assertRedirect();

    expect($profiles[1]->fresh()->status)->toBe(VendorStatus::Approved);
});

it('blocks bulk vendor actions for staff without vendors.approve', function () {
    $profiles = VendorProfile::factory()->count(2)->create(['status' => VendorStatus::Pending]);

    $this->actingAs(bulkAdmin('Support Agent'))
        ->post(adminHost('vendors/bulk'), [
            'action' => 'approve',
            'uuids' => $profiles->pluck('uuid')->all(),
        ])
        ->assertForbidden();

    expect($profiles->first()->fresh()->status)->toBe(VendorStatus::Pending);
});
