<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\BuiltInProductFieldSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(BuiltInProductFieldSeeder::class);

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
});

function videoVendorHost(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.vendor_domain')).$path;
}

function videoProductPayload(array $overrides = []): array
{
    return array_merge([
        'category_id' => test()->category->id,
        'name' => 'A TELEVISION',
        'description' => 'A large one.',
        'price_naira' => 250000,
        'stock_quantity' => 2,
    ], $overrides);
}

it('saves a YouTube link with the listing', function () {
    $this->actingAs($this->vendorUser)
        ->post(videoVendorHost('/products'), videoProductPayload([
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]))
        ->assertRedirect();

    expect(Product::query()->first()->video_url)
        ->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
});

it('is optional', function () {
    $this->actingAs($this->vendorUser)
        ->post(videoVendorHost('/products'), videoProductPayload())
        ->assertSessionHasNoErrors();

    expect(Product::query()->first()->video_url)->toBeNull();
});

it('refuses a link the product page could not play', function () {
    $this->actingAs($this->vendorUser)
        ->post(videoVendorHost('/products'), videoProductPayload([
            'video_url' => 'https://example.com/my-video.mp4',
        ]))
        ->assertSessionHasErrors('video_url');

    // A rejected link must not leave a half-made listing behind.
    expect(Product::query()->count())->toBe(0);
});

it('names what a vendor may paste when it refuses one', function () {
    $this->actingAs($this->vendorUser)
        ->post(videoVendorHost('/products'), videoProductPayload(['video_url' => 'not a link']))
        ->assertSessionHasErrors(['video_url' => 'Paste a YouTube or Vimeo link — for example https://www.youtube.com/watch?v=xxxxxxxxxxx']);
});

it('stores the link exactly, because video ids are case-sensitive', function () {
    // Everything else on a product is uppercased on the way in. Doing that
    // here would turn the id into a different video, or none.
    $this->actingAs($this->vendorUser)
        ->post(videoVendorHost('/products'), videoProductPayload([
            'video_url' => 'https://youtu.be/AbCdEfGhIjK',
        ]))
        ->assertSessionHasNoErrors();

    expect(Product::query()->first()->video_url)->toBe('https://youtu.be/AbCdEfGhIjK');
});

it('lets a vendor clear the link again', function () {
    $product = Product::factory()->create([
        'vendor_id' => $this->vendorProfile->id,
        'category_id' => $this->category->id,
        'video_url' => 'https://youtu.be/dQw4w9WgXcQ',
    ]);

    $this->actingAs($this->vendorUser)
        ->post(videoVendorHost('/products/'.$product->uuid), videoProductPayload(['video_url' => '']))
        ->assertSessionHasNoErrors();

    expect($product->refresh()->video_url)->toBeNull();
});

it('does not store a link of nothing but spaces', function () {
    $product = Product::factory()->create([
        'vendor_id' => $this->vendorProfile->id,
        'category_id' => $this->category->id,
        'video_url' => 'https://youtu.be/dQw4w9WgXcQ',
    ]);

    // Otherwise the next edit fails validation on a link nobody can see.
    $this->actingAs($this->vendorUser)
        ->post(videoVendorHost('/products/'.$product->uuid), videoProductPayload(['video_url' => '   ']))
        ->assertSessionHasNoErrors();

    expect($product->refresh()->video_url)->toBeNull();
});

it('hands the link back when the listing is reopened', function () {
    $product = Product::factory()->create([
        'vendor_id' => $this->vendorProfile->id,
        'category_id' => $this->category->id,
        'video_url' => 'https://youtu.be/dQw4w9WgXcQ',
    ]);

    // The form posts every field on save, so a payload missing the link would
    // wipe it the next time somebody fixed a typo in the price.
    $this->actingAs($this->vendorUser)
        ->getJson(videoVendorHost('/products/'.$product->uuid.'/details'))
        ->assertJsonPath('product.videoUrl', 'https://youtu.be/dQw4w9WgXcQ');
});

it('offers the field on the vendor form, worded by admin', function () {
    $this->actingAs($this->vendorUser)
        ->get(videoVendorHost('/products'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('builtInFields.video_url.label', 'Video link'));
});

// ── What a shopper gets ────────────────────────────────────────────────

it('plays the video on the product page', function () {
    $product = Product::factory()->approved()->create([
        'vendor_id' => $this->vendorProfile->id,
        'category_id' => $this->category->id,
        'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'status' => ProductStatus::Approved,
    ]);

    $this->get('http://'.strtolower((string) config('app.url_host', 'firstmaket.localhost:8000')).'/product/'.$product->slug)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('product.video.embedUrl', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
            ->where('product.video.providerName', 'YouTube'));
});

it('sends the page a rebuilt embed url, never the vendor string', function () {
    /*
     * The product page drops this straight into an iframe. Extra query
     * parameters on the original must not survive, because that is the one
     * place a crafted link could change what the frame loads.
     */
    $product = Product::factory()->create([
        'vendor_id' => $this->vendorProfile->id,
        'category_id' => $this->category->id,
        'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=9&list=PLnasty',
        'status' => ProductStatus::Approved,
    ]);

    $this->get('/product/'.$product->slug)
        ->assertInertia(fn ($page) => $page
            ->where('product.video.embedUrl', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'));
});

it('sends null when there is no video', function () {
    $product = Product::factory()->create([
        'vendor_id' => $this->vendorProfile->id,
        'category_id' => $this->category->id,
        'status' => ProductStatus::Approved,
    ]);

    $this->get('/product/'.$product->slug)
        ->assertInertia(fn ($page) => $page->where('product.video', null));
});

it('shows no player for a link that is no longer supported', function () {
    // Rows written before the provider list changed must degrade to no video
    // rather than to a broken frame.
    $product = Product::factory()->create([
        'vendor_id' => $this->vendorProfile->id,
        'category_id' => $this->category->id,
        'video_url' => 'https://example.com/legacy.mp4',
        'status' => ProductStatus::Approved,
    ]);

    $this->get('/product/'.$product->slug)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('product.video', null));
});
