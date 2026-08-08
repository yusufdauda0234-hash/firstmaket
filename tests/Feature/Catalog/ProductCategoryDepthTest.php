<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * A listing belongs on the most specific shelf there is.
 *
 * The form used to offer every active category as one flat list, so
 * "Electronics" and "Smartphones" appeared side by side as if they were
 * alternatives. A phone filed on the parent then never turned up when a
 * shopper narrowed to Smartphones — which is the only reason the
 * sub-category exists.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->vendorUser = User::factory()->create([
        'user_type' => UserType::Vendor,
        'phone_verified_at' => now(),
    ]);
    $this->vendorUser->assignRole('Vendor');

    $this->vendor = VendorProfile::factory()->create([
        'user_id' => $this->vendorUser->id,
        'status' => VendorStatus::Approved,
    ]);

    $this->parent = Category::factory()->create(['name' => 'Electronics', 'is_active' => true]);
    $this->child = Category::factory()->create([
        'name' => 'Smartphones',
        'parent_id' => $this->parent->id,
        'is_active' => true,
    ]);
});

function vendorFormUrl(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.vendor_domain')).'/products'.$path;
}

function listingPayload(int $categoryId): array
{
    return [
        'category_id' => $categoryId,
        'name' => 'Test listing',
        'description' => 'Something worth selling, described at length for the validator.',
        'price_naira' => 50000,
        'stock_quantity' => 5,
    ];
}

it('sends the catalogue as a tree, not a flat list', function () {
    $this->actingAs($this->vendorUser)
        ->get(vendorFormUrl('/create'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $categories = collect($page->toArray()['props']['categories']);

            // Only parents at the top level, each carrying its own children.
            expect($categories)->toHaveCount(1)
                ->and($categories->first()['name'])->toBe('Electronics')
                ->and($categories->first()['children'])->toHaveCount(1)
                ->and($categories->first()['children'][0]['name'])->toBe('Smartphones');
        });
});

it('refuses a listing filed on a parent that has sub-categories', function () {
    $this->actingAs($this->vendorUser)
        ->post(vendorFormUrl(), listingPayload($this->parent->id))
        ->assertSessionHasErrors('category_id');

    expect(Product::query()->count())->toBe(0);
});

it('accepts a listing on the sub-category', function () {
    $this->actingAs($this->vendorUser)
        ->post(vendorFormUrl(), listingPayload($this->child->id))
        ->assertSessionHasNoErrors();

    expect(Product::query()->first()->category_id)->toBe($this->child->id);
});

it('accepts a category that has no sub-categories', function () {
    // A parent with nothing under it is a shelf in its own right.
    $standalone = Category::factory()->create(['name' => 'Books', 'is_active' => true]);

    $this->actingAs($this->vendorUser)
        ->post(vendorFormUrl(), listingPayload($standalone->id))
        ->assertSessionHasNoErrors();

    expect(Product::query()->first()->category_id)->toBe($standalone->id);
});

it('ignores a sub-category that has been switched off', function () {
    // Deactivating the only child makes the parent a shelf again, rather than
    // leaving vendors unable to list anything under it at all.
    $this->child->update(['is_active' => false]);

    $this->actingAs($this->vendorUser)
        ->post(vendorFormUrl(), listingPayload($this->parent->id))
        ->assertSessionHasNoErrors();

    expect(Product::query()->first()->category_id)->toBe($this->parent->id);
});

it('says which category needs narrowing', function () {
    // "Invalid category" tells a vendor nothing about what to do next.
    $this->actingAs($this->vendorUser)
        ->post(vendorFormUrl(), listingPayload($this->parent->id))
        ->assertSessionHasErrors([
            'category_id' => 'Choose the sub-category this belongs to — “Electronics” has more specific ones under it.',
        ]);
});
