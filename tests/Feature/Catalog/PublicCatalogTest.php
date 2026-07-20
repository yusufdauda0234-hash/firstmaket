<?php

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
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
    Product::factory()->approved()->create(['category_id' => $this->category->id, 'name' => 'Visible TV']);
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

it('sorts the catalog by price', function () {
    Product::factory()->approved()->create(['category_id' => $this->category->id, 'name' => 'Cheap', 'price_kobo' => 100000]);
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

it('returns 404 for a product that is not approved', function () {
    $pending = Product::factory()->pending()->create(['category_id' => $this->category->id]);

    $this->get("/product/{$pending->slug}")->assertNotFound();
});

it('suggests only approved products in search autocomplete', function () {
    Product::factory()->approved()->create(['category_id' => $this->category->id, 'name' => 'Samsung Visible TV']);
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
