<?php

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\HomeDataService;
use App\Shared\Enums\ProductStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    $this->electronics = Category::factory()->create([
        'name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true, 'parent_id' => null,
    ]);

    $this->cameras = Category::factory()->create([
        'name' => 'Cameras', 'slug' => 'cameras', 'is_active' => true, 'parent_id' => $this->electronics->id,
    ]);

    $this->fashion = Category::factory()->create([
        'name' => 'Fashion', 'slug' => 'fashion', 'is_active' => true, 'parent_id' => null,
    ]);
});

function menuUrl(string $slug = ''): string
{
    return '/catalog/menu-products'.($slug === '' ? '' : '?category='.$slug);
}

function cameraListing(string $name = 'A CAMERA'): Product
{
    return Product::factory()->create([
        'name' => $name,
        'category_id' => test()->cameras->id,
        'status' => ProductStatus::Approved,
        'approved_at' => now(),
    ]);
}

it('shows a parent category the products filed under its children', function () {
    /*
     * The regression this covers: listings sit on the most specific category
     * there is, so a camera is filed under Cameras and never under
     * Electronics. Matching the slug exactly meant hovering "Electronics" in
     * the header menu returned an empty panel.
     */
    cameraListing();

    $this->getJson(menuUrl('electronics'))
        ->assertOk()
        ->assertJsonCount(1, 'products')
        ->assertJsonPath('products.0.name', 'A Camera');
});

it('still shows a leaf category its own products', function () {
    cameraListing();

    $this->getJson(menuUrl('cameras'))
        ->assertOk()
        ->assertJsonCount(1, 'products');
});

it('does not leak products from a different branch', function () {
    cameraListing();

    $this->getJson(menuUrl('fashion'))
        ->assertOk()
        ->assertJsonCount(0, 'products');
});

it('returns everything when no category is asked for', function () {
    cameraListing('ONE');
    cameraListing('TWO');

    $this->getJson(menuUrl())
        ->assertOk()
        ->assertJsonCount(2, 'products');
});

it('returns nothing for a slug that is not a category', function () {
    cameraListing();

    // Falling back to "everything" would show unrelated products under a
    // heading claiming they belong to something else.
    $this->getJson(menuUrl('no-such-category'))
        ->assertOk()
        ->assertJsonCount(0, 'products');
});

it('only ever shows approved listings', function () {
    Product::factory()->create([
        'category_id' => $this->cameras->id,
        'status' => ProductStatus::Draft,
    ]);

    $this->getJson(menuUrl('electronics'))
        ->assertOk()
        ->assertJsonCount(0, 'products');
});

// ── The menu needs sub-categories to drill into ─────────────────────────

it('gives the header menu each category its sub-categories', function () {
    // Without these the per-category panel had nothing at all to render.
    $categories = collect(app(HomeDataService::class)->categories());
    $electronics = $categories->firstWhere('slug', 'electronics');

    expect($electronics['children'])->toHaveCount(1)
        ->and($electronics['children'][0]['slug'])->toBe('cameras');
});

it('keeps sub-categories nested rather than listing them as top level', function () {
    // "Cameras" beside "Electronics" would read as though they were equals.
    $slugs = collect(app(HomeDataService::class)->categories())->pluck('slug');

    expect($slugs)->toContain('electronics', 'fashion')
        ->and($slugs)->not->toContain('cameras');
});

it('gives a childless category an empty list rather than nothing', function () {
    // The menu reads `children` on every entry, so it must always be present.
    $fashion = collect(app(HomeDataService::class)->categories())->firstWhere('slug', 'fashion');

    expect($fashion)->toHaveKey('children')
        ->and($fashion['children'])->toBe([]);
});

// ── Every page must hand the header the same shape ──────────────────────

it('sends the nested shape on the catalogue page too', function () {
    /*
     * These pages passed their own flat list of every active category under
     * the same prop the shared header reads, so on them the menu listed
     * "Cameras" beside "Electronics" and had no children to drill into.
     */
    $this->get('/catalog')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('categories.0.slug', 'electronics')
            ->where('categories.0.children.0.slug', 'cameras'));
});

it('sends the nested shape on a product page too', function () {
    $product = cameraListing();

    $this->get('/product/'.$product->slug)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('categories.0.children'));
});

it('never lists a sub-category as top level on the catalogue page', function () {
    $this->get('/catalog')
        ->assertInertia(function ($page) {
            $slugs = collect($page->toArray()['props']['categories'])->pluck('slug');

            expect($slugs)->not->toContain('cameras');
        });
});

// ── Newly approved listings must not wait on a cache ────────────────────

it('shows a newly approved listing without waiting for the cache to expire', function () {
    /*
     * The home strips are cached for five minutes and nothing used to clear
     * them, so approving a product left it invisible on the storefront for
     * minutes — indistinguishable from the approval having failed.
     */
    $service = app(HomeDataService::class);

    expect($service->newestProducts())->toHaveCount(0);

    cameraListing('BRAND NEW');

    expect(collect($service->newestProducts())->pluck('name'))->toContain('Brand New');
});

it('shows a new category in the menu straight away', function () {
    $service = app(HomeDataService::class);
    $service->categories();

    Category::factory()->create([
        'name' => 'Groceries', 'slug' => 'groceries', 'is_active' => true, 'parent_id' => null,
    ]);

    expect(collect($service->categories())->pluck('slug'))->toContain('groceries');
});

it('drops a deleted listing from the cached strips', function () {
    $product = cameraListing('GOING AWAY');
    expect(collect(app(HomeDataService::class)->newestProducts())->pluck('name'))->toContain('Going Away');

    $product->delete();

    expect(collect(app(HomeDataService::class)->newestProducts())->pluck('name'))->not->toContain('Going Away');
});

it('leaves an inactive sub-category out of the menu', function () {
    Category::factory()->create([
        'name' => 'Retired', 'slug' => 'retired', 'is_active' => false, 'parent_id' => $this->electronics->id,
    ]);

    $electronics = collect(app(HomeDataService::class)->categories())->firstWhere('slug', 'electronics');

    expect(collect($electronics['children'])->pluck('slug'))->not->toContain('retired');
});
