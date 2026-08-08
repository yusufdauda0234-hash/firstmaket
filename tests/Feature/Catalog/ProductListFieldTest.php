<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

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

    $this->keyFeatures = ProductAttribute::query()->create([
        'key' => 'key_features',
        'label' => 'Key features',
        'type' => 'bullet_list',
        'category_id' => $this->category->id,
        'is_active' => true,
    ]);
});

function listVendorHost(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.vendor_domain')).$path;
}

function listProductPayload(array $attributes): array
{
    return [
        'category_id' => test()->category->id,
        'name' => 'A CAMERA',
        'description' => 'A good one.',
        'price_naira' => 900000,
        'stock_quantity' => 3,
        'attributes' => $attributes,
    ];
}

function listProduct(): Product
{
    return Product::factory()->create([
        'vendor_id' => test()->vendorProfile->id,
        'category_id' => test()->category->id,
        'status' => ProductStatus::Approved,
    ]);
}

it('saves a list field one item per line', function () {
    $this->actingAs($this->vendorUser)
        ->post(listVendorHost('/products'), listProductPayload([
            'key_features' => ['45.7MP full-frame sensor', '153-point AF system', '4K UHD at 30fps'],
        ]))
        ->assertSessionHasNoErrors();

    expect(ProductAttributeValue::query()->first()->value)
        ->toBe(['45.7MP full-frame sensor', '153-point AF system', '4K UHD at 30fps']);
});

it('gives the product page the items, not one run-on paragraph', function () {
    /*
     * The complaint this answers: key features arrived as a single wall of
     * text with " - " between the points, because the only long-form type was
     * a textarea and the page could only print a string.
     */
    $product = listProduct();

    ProductAttributeValue::query()->create([
        'product_id' => $product->id,
        'product_attribute_id' => $this->keyFeatures->id,
        'value' => ['45.7MP sensor', '153-point AF'],
    ]);

    $this->get('/product/'.$product->slug)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('product.specifications.0.label', 'Key features')
            ->where('product.specifications.0.listStyle', 'bullet')
            ->where('product.specifications.0.items', ['45.7MP sensor', '153-point AF']));
});

it('marks a numbered list as numbered', function () {
    $steps = ProductAttribute::query()->create([
        'key' => 'setup_steps',
        'label' => 'Setup',
        'type' => 'numbered_list',
        'category_id' => $this->category->id,
        'is_active' => true,
    ]);

    $product = listProduct();

    ProductAttributeValue::query()->create([
        'product_id' => $product->id,
        'product_attribute_id' => $steps->id,
        'value' => ['Unbox it', 'Charge it'],
    ]);

    $this->get('/product/'.$product->slug)
        ->assertInertia(fn ($page) => $page
            ->where('product.specifications.0.listStyle', 'numbered'));
});

it('leaves an ordinary field without a list style', function () {
    $colour = ProductAttribute::query()->create([
        'key' => 'colour', 'label' => 'Colour', 'type' => 'text',
        'category_id' => $this->category->id, 'is_active' => true,
    ]);

    $product = listProduct();

    ProductAttributeValue::query()->create([
        'product_id' => $product->id,
        'product_attribute_id' => $colour->id,
        'value' => 'Black',
    ]);

    // The page prints `value` whenever items is empty, so an ordinary field
    // must not accidentally acquire a list.
    $this->get('/product/'.$product->slug)
        ->assertInertia(fn ($page) => $page
            ->where('product.specifications.0.listStyle', null)
            ->where('product.specifications.0.items', []));
});

it('rejects a list longer than the table can carry', function () {
    $this->actingAs($this->vendorUser)
        ->post(listVendorHost('/products'), listProductPayload([
            'key_features' => array_fill(0, 31, 'A point'),
        ]))
        ->assertSessionHasErrors('attributes.key_features');
});

it('rejects an item longer than a line', function () {
    $this->actingAs($this->vendorUser)
        ->post(listVendorHost('/products'), listProductPayload([
            'key_features' => [str_repeat('a', 301)],
        ]))
        ->assertSessionHasErrors('attributes.key_features.0');
});

it('offers both list types in the admin field builder', function () {
    $staff = User::factory()->create(['user_type' => UserType::Staff]);
    $staff->assignRole('Administrator');
    $staff->forceFill(['two_factor_confirmed_at' => now()])->save();

    // The dropdown is built from the enum, so this is all the new types need
    // in order to be offered.
    $this->actingAs($staff)
        ->get('http://'.strtolower((string) config('app.admin_domain')).'/catalog/product-fields')
        ->assertOk()
        ->assertInertia(function ($page) {
            $values = collect($page->toArray()['props']['fieldTypes'])->pluck('value');

            expect($values)->toContain('bullet_list', 'numbered_list');
        });
});
