<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\BuiltInProductFieldSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(BuiltInProductFieldSeeder::class);
});

function fieldStaff(string $role = 'Administrator'): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->assignRole($role);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user;
}

function fieldUrl(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/catalog/product-fields'
        .($path === '' ? '' : '/'.ltrim($path, '/'));
}

function approvedVendorUser(): User
{
    $user = User::factory()->create(['user_type' => UserType::Vendor, 'phone_verified_at' => now()]);
    $user->assignRole('Vendor');

    VendorProfile::factory()->create([
        'user_id' => $user->id,
        'status' => VendorStatus::Approved,
    ]);

    return $user;
}

function builtIn(string $key): ProductAttribute
{
    return ProductAttribute::query()->where('system_key', $key)->firstOrFail();
}

it('lists the fields every product has, so the page is never empty', function () {
    // The inconsistency this fixes: the vendor form showed six fields the admin
    // manager knew nothing about, so it looked like nothing was configured.
    $this->actingAs(fieldStaff())
        ->get(fieldUrl())
        ->assertOk()
        ->assertInertia(function ($page) {
            $labels = collect($page->toArray()['props']['attributes'])->pluck('label');

            expect($labels)->toContain('Category', 'Product name', 'Description', 'Price (₦)', 'Stock quantity');
        });
});

it('marks them as built in', function () {
    $this->actingAs(fieldStaff())
        ->get(fieldUrl())
        ->assertInertia(function ($page) {
            $name = collect($page->toArray()['props']['attributes'])->firstWhere('systemKey', 'name');

            expect($name['isBuiltIn'])->toBeTrue();
        });
});

it('mirrors what the server already enforces', function () {
    // These descriptions are only useful if they match StoreProductRequest.
    expect(builtIn('name')->is_required)->toBeTrue()
        ->and(builtIn('price_naira')->is_required)->toBeTrue()
        ->and(builtIn('stock_quantity')->is_required)->toBeTrue()
        ->and(builtIn('description')->is_required)->toBeTrue()
        ->and(builtIn('images')->is_required)->toBeFalse();
});

it('lets staff reword one', function () {
    $price = builtIn('price_naira');

    $this->actingAs(fieldStaff())
        ->put(fieldUrl((string) $price->id), ['label' => 'Selling price (₦)'])
        ->assertRedirect();

    expect($price->refresh()->label)->toBe('Selling price (₦)');
});

it('keeps the existing hint when the label is changed', function () {
    $price = builtIn('price_naira');
    $price->forceFill(['help_text' => 'What the shopper pays today.'])->save();

    // The hint and placeholder boxes were taken off the form. Reading them
    // from the request anyway would blank whatever a field already had every
    // time somebody fixed a typo in its label.
    $this->actingAs(fieldStaff())
        ->put(fieldUrl((string) $price->id), ['label' => 'Price (₦)'])
        ->assertRedirect();

    expect($price->refresh()->help_text)->toBe('What the shopper pays today.');
});

it('will not let a built-in be made optional', function () {
    $price = builtIn('price_naira');

    $this->actingAs(fieldStaff())
        ->put(fieldUrl((string) $price->id), [
            'label' => 'Price (₦)',
            'is_required' => false,
            'type' => 'text',
            'category_id' => '',
        ])
        ->assertRedirect();

    $price->refresh();

    // A configuration screen must not be able to claim price is optional when
    // StoreProductRequest still demands it.
    expect($price->is_required)->toBeTrue()
        ->and($price->type->value)->toBe('number');
});

it('refuses to delete a built-in', function () {
    $name = builtIn('name');

    $this->actingAs(fieldStaff())
        ->delete(fieldUrl((string) $name->id))
        ->assertSessionHasErrors('field');

    expect(ProductAttribute::query()->where('system_key', 'name')->exists())->toBeTrue();
});

it('leaves built-ins alone in a bulk switch-off but still does the rest', function () {
    $custom = ProductAttribute::query()->create([
        'key' => 'colour', 'label' => 'Colour', 'type' => 'text', 'is_active' => true,
    ]);

    $this->actingAs(fieldStaff())
        ->post(fieldUrl('bulk'), [
            'action' => 'deactivate',
            'ids' => [$custom->id, builtIn('price_naira')->id],
        ])
        ->assertRedirect();

    // The custom one switches; price stays on, because every product needs it.
    expect($custom->fresh()->is_active)->toBeFalse()
        ->and(builtIn('price_naira')->is_active)->toBeTrue();
});

it('does not duplicate them when the seeder runs again', function () {
    $before = ProductAttribute::query()->builtIn()->pluck('system_key')->sort()->values();

    $this->seed(BuiltInProductFieldSeeder::class);

    $after = ProductAttribute::query()->builtIn()->pluck('system_key')->sort()->values();

    // Compared against what the seeder itself defines rather than a count, so
    // adding a built-in does not turn this into a false failure.
    expect($after->all())->toBe($before->all())
        ->and($after->duplicates())->toBeEmpty();
});

it('keeps a reworded label when the seeder runs again', function () {
    builtIn('name')->update(['label' => 'Item title']);

    $this->seed(BuiltInProductFieldSeeder::class);

    // Re-seeding must not undo someone's wording.
    expect(builtIn('name')->label)->toBe('Item title');
});

// ── The vendor form is driven by these, not by hardcoded strings ─────────

it('sends the built-in wording to the vendor form', function () {
    $vendorUser = approvedVendorUser();

    $this->actingAs($vendorUser)
        ->get('http://'.strtolower((string) config('app.vendor_domain')).'/products/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('builtInFields.name')
            ->has('builtInFields.price_naira')
            ->where('builtInFields.name.label', 'Product name'));
});

it('sends a reworded built-in through to the vendor form', function () {
    /*
     * The whole point of the screen: the labels used to be hardcoded in the
     * form, so rewording one here changed nothing.
     *
     * What this proves is that the new wording reaches the page as a prop.
     * It cannot prove the JSX reads it — React does not run server-side, so
     * a label reverted to a hard string would still pass. Checking that needs
     * a browser, and the form falls back to the original wording per field
     * precisely so a missed one degrades to what was there before.
     */
    $price = builtIn('price_naira');

    $this->actingAs(fieldStaff())
        ->put(fieldUrl((string) $price->id), ['label' => 'Selling price'])
        ->assertRedirect();

    $this->actingAs(approvedVendorUser())
        ->get('http://'.strtolower((string) config('app.vendor_domain')).'/products/create')
        ->assertInertia(fn ($page) => $page->where('builtInFields.price_naira.label', 'Selling price'));
});

// ── The product list, which is where vendors actually add a listing ──────

it('sends the same field configuration to the product list', function () {
    /*
     * There are two product forms. Vendors use the modal on this page, not
     * /products/create — so this was the screen still showing hardcoded
     * labels, a flat category list and none of the admin-defined fields
     * after the standalone page had already been fixed.
     */
    $category = Category::factory()->create(['is_active' => true]);

    ProductAttribute::query()->create([
        'key' => 'brand', 'label' => 'Brand', 'type' => 'text',
        'category_id' => $category->id, 'is_active' => true,
    ]);

    $this->actingAs(approvedVendorUser())
        ->get('http://'.strtolower((string) config('app.vendor_domain')).'/products')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('builtInFields.name')
            ->has('builtInFields.price_naira')
            ->where('attributeFieldsByCategory.'.$category->id.'.0.label', 'Brand'));
});

it('gives the product list categories it can nest', function () {
    $parent = Category::factory()->create(['is_active' => true]);
    Category::factory()->create(['is_active' => true, 'parent_id' => $parent->id]);

    // The modal reads `children` to decide whether a second dropdown is
    // needed. A flat list makes every parent look like a valid shelf.
    $this->actingAs(approvedVendorUser())
        ->get('http://'.strtolower((string) config('app.vendor_domain')).'/products')
        ->assertInertia(fn ($page) => $page->has('categories.0.children'));
});

it('hands back saved answers when a listing is opened for editing', function () {
    $vendorUser = approvedVendorUser();
    $category = Category::factory()->create(['is_active' => true]);

    $attribute = ProductAttribute::query()->create([
        'key' => 'brand', 'label' => 'Brand', 'type' => 'text',
        'category_id' => $category->id, 'is_active' => true,
    ]);

    $product = Product::factory()->create([
        'vendor_id' => $vendorUser->vendorProfile->id,
        'category_id' => $category->id,
    ]);

    ProductAttributeValue::query()->create([
        'product_id' => $product->id,
        'product_attribute_id' => $attribute->id,
        'value' => 'SAMSUNG',
    ]);

    // The modal posts every field back on save. If the fetch does not return
    // what is already stored, editing the price would wipe the brand.
    $this->actingAs($vendorUser)
        ->getJson('http://'.strtolower((string) config('app.vendor_domain')).'/products/'.$product->uuid.'/details')
        ->assertOk()
        ->assertJsonPath('product.attributes.brand', 'SAMSUNG');
});
