<?php

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Campaign;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Shared\Enums\AttributeType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Sprint 3: the customer-facing catalog must only ever show Approved
 * products.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->category = Category::factory()->create(['slug' => 'electronics', 'name' => 'Electronics']);
});

it('shows only approved products in the public catalog', function () {
    Product::factory()->approved()->create(['category_id' => $this->category->id, 'name' => 'VISIBLE TV']);
    Product::factory()->pending()->create(['category_id' => $this->category->id, 'name' => 'Hidden TV']);
    Product::factory()->create(['category_id' => $this->category->id, 'name' => 'Draft TV']);

    $this->get('/catalog')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Catalog')
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Visible TV'));
});

it('filters the catalog by search query and category', function () {
    $other = Category::factory()->create(['slug' => 'fashion']);
    Product::factory()->approved()->create(['category_id' => $this->category->id, 'name' => 'Samsung Freezer']);
    Product::factory()->approved()->create(['category_id' => $this->category->id, 'name' => 'LG Television']);
    Product::factory()->approved()->create(['category_id' => $other->id, 'name' => 'Ankara Gown']);

    $this->get('/catalog?query=freezer')
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Samsung Freezer'));

    $this->get('/catalog?category=electronics')
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 2));
});

it('shows a live campaign price in public catalog results', function () {
    $product = Product::factory()->approved()->create([
        'category_id' => $this->category->id,
        'price_kobo' => 100_000,
    ]);
    $campaign = Campaign::query()->create([
        'name' => 'Weekend Sale',
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'is_active' => true,
    ]);
    $campaign->products()->attach($product, ['sale_price_kobo' => 75_000]);

    $this->get('/catalog')
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.priceKobo', 75_000)
            ->where('products.data.0.compareAtPriceKobo', 100_000));
});

it('sorts the catalog by price', function () {
    Product::factory()->approved()->create(['category_id' => $this->category->id, 'name' => 'CHEAP', 'price_kobo' => 100000]);
    Product::factory()->approved()->create(['category_id' => $this->category->id, 'name' => 'Costly', 'price_kobo' => 900000]);

    $this->get('/catalog?sort=price_asc')
        ->assertInertia(fn (Assert $page) => $page->where('products.data.0.name', 'Cheap'));

    $this->get('/catalog?sort=price_desc')
        ->assertInertia(fn (Assert $page) => $page->where('products.data.0.name', 'Costly'));
});

it('serves an approved product detail page to guests', function () {
    $product = Product::factory()->approved()->create(['category_id' => $this->category->id]);

    $this->get("/product/{$product->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/ProductShow')
            ->where('product.name', $product->name));
});

it('compares approved products in the requested order', function () {
    $first = Product::factory()->approved()->create(['category_id' => $this->category->id, 'name' => 'First Product']);
    $second = Product::factory()->approved()->create(['category_id' => $this->category->id, 'name' => 'Second Product']);
    $hidden = Product::factory()->pending()->create(['category_id' => $this->category->id, 'name' => 'Hidden Product']);

    $this->get("/compare?products={$second->uuid},{$hidden->uuid},{$first->uuid}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Compare')
            ->has('products', 2)
            ->where('products.0.uuid', $second->uuid)
            ->where('products.1.uuid', $first->uuid));
});

it('lines admin-defined fields up across the compared products', function () {
    $ram = ProductAttribute::query()->create([
        'category_id' => $this->category->id,
        'key' => 'ram',
        'label' => 'RAM',
        'type' => AttributeType::Number,
        'unit' => 'GB',
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $colour = ProductAttribute::query()->create([
        'category_id' => $this->category->id,
        'key' => 'colour',
        'label' => 'Colour',
        'type' => AttributeType::Text,
        'is_active' => true,
        'sort_order' => 2,
    ]);

    $a = Product::factory()->approved()->create(['category_id' => $this->category->id]);
    $b = Product::factory()->approved()->create(['category_id' => $this->category->id]);

    // Differs between the two.
    ProductAttributeValue::query()->create(['product_id' => $a->id, 'product_attribute_id' => $ram->id, 'value' => 8]);
    ProductAttributeValue::query()->create(['product_id' => $b->id, 'product_attribute_id' => $ram->id, 'value' => 12]);
    // Identical, so the row should mark itself as such.
    ProductAttributeValue::query()->create(['product_id' => $a->id, 'product_attribute_id' => $colour->id, 'value' => 'Black']);
    ProductAttributeValue::query()->create(['product_id' => $b->id, 'product_attribute_id' => $colour->id, 'value' => 'Black']);

    $this->get("/compare?products={$a->uuid},{$b->uuid}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Compare')
            ->has('specRows', 2)
            ->where('specRows.0.label', 'RAM')
            ->where('specRows.0.same', false)
            ->where("specRows.0.values.{$a->uuid}", '8 GB')
            ->where("specRows.0.values.{$b->uuid}", '12 GB')
            ->where('specRows.1.label', 'Colour')
            ->where('specRows.1.same', true));
});

it('keeps a field one product left blank, showing the gap as a difference', function () {
    $warranty = ProductAttribute::query()->create([
        'category_id' => $this->category->id,
        'key' => 'warranty',
        'label' => 'Warranty',
        'type' => AttributeType::Text,
        'is_active' => true,
    ]);

    $a = Product::factory()->approved()->create(['category_id' => $this->category->id]);
    $b = Product::factory()->approved()->create(['category_id' => $this->category->id]);

    ProductAttributeValue::query()->create([
        'product_id' => $a->id,
        'product_attribute_id' => $warranty->id,
        'value' => '2 years',
    ]);

    $this->get("/compare?products={$a->uuid},{$b->uuid}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('specRows', 1)
            ->where("specRows.0.values.{$a->uuid}", '2 years')
            ->where("specRows.0.values.{$b->uuid}", null)
            ->where('specRows.0.same', false));
});

it('leaves out a field none of the compared products filled in', function () {
    ProductAttribute::query()->create([
        'category_id' => $this->category->id,
        'key' => 'unused',
        'label' => 'Unused',
        'type' => AttributeType::Text,
        'is_active' => true,
    ]);

    $a = Product::factory()->approved()->create(['category_id' => $this->category->id]);
    $b = Product::factory()->approved()->create(['category_id' => $this->category->id]);

    $this->get("/compare?products={$a->uuid},{$b->uuid}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('specRows', 0));
});

it('returns 404 for a product that is not approved', function () {
    $pending = Product::factory()->pending()->create(['category_id' => $this->category->id]);

    $this->get("/product/{$pending->slug}")->assertNotFound();
});

it('suggests only approved products in search autocomplete', function () {
    Product::factory()->approved()->create(['category_id' => $this->category->id, 'name' => 'SAMSUNG VISIBLE TV']);
    Product::factory()->pending()->create(['category_id' => $this->category->id, 'name' => 'Samsung Hidden TV']);

    $this->getJson('/catalog/suggest?query=samsung')
        ->assertOk()
        ->assertJsonCount(1, 'suggestions')
        ->assertJsonPath('suggestions.0.name', 'Samsung Visible TV');

    $this->getJson('/catalog/suggest?query=s')
        ->assertOk()
        ->assertJsonCount(0, 'suggestions');
});

it('feeds the home page product sections from the approved catalog', function () {
    Product::factory()->approved()->count(3)->create(['category_id' => $this->category->id]);
    Product::factory()->pending()->create(['category_id' => $this->category->id]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Home')
            ->has('newestProducts', 3));
});
