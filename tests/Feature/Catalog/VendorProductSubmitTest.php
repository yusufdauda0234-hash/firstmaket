<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Services\ProductAttributeService;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\BuiltInProductFieldSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    // The rows that caused the regression — every test here runs with them
    // present, because that is the state the bug needed.
    $this->seed(BuiltInProductFieldSeeder::class);
    Notification::fake();
    Storage::fake('public');

    $this->category = Category::factory()->create(['is_active' => true]);

    $user = User::factory()->create(['user_type' => UserType::Vendor]);
    $user->assignRole('Vendor');
    $this->vendor = VendorProfile::factory()->create([
        'user_id' => $user->id,
        'status' => VendorStatus::Approved,
    ]);
    $this->vendorUser = $user;
});

function vendorProductsUrl(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.vendor_domain')).'/products'
        .($path === '' ? '' : '/'.ltrim($path, '/'));
}

/** @return array<string, mixed> */
function productPayload(array $overrides = []): array
{
    return [
        'category_id' => test()->category->id,
        'name' => 'AWARA',
        'description' => 'Freshly prepared Awara (soybean tofu), soft and nutritious.',
        'price_naira' => 1000,
        'stock_quantity' => 15,
        'submit' => true,
        ...$overrides,
    ];
}

it('never asks a vendor for the built-in fields a second time', function () {
    // The regression: built-ins are ProductAttribute rows, and the vendor form
    // reads that same table for its custom fields. Left unfiltered they came
    // back as attributes.name, attributes.price_naira and so on — keys the form
    // has no input for, so submissions failed with errors nobody could see.
    $fields = app(ProductAttributeService::class)->forCategory($this->category);

    expect($fields)->toHaveCount(0)
        ->and(array_keys(app(ProductAttributeService::class)->rules($fields)))->toBe([]);
});

it('lets a vendor submit a product for approval', function () {
    $this->actingAs($this->vendorUser)
        ->post(vendorProductsUrl(), productPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    expect(Product::query()->where('name', 'AWARA')->exists())->toBeTrue();
});

it('saves a draft without submitting it', function () {
    $this->actingAs($this->vendorUser)
        ->post(vendorProductsUrl(), productPayload(['submit' => false]))
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');
});

it('accepts photos with the submission', function () {
    $this->actingAs($this->vendorUser)
        ->post(vendorProductsUrl(), productPayload([
            'images' => [
                UploadedFile::fake()->image('one.jpg'),
                UploadedFile::fake()->image('two.jpg'),
                UploadedFile::fake()->image('three.jpg'),
            ],
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Product::query()->where('name', 'AWARA')->first()->images()->count())->toBe(3);
});

it('names the offending photo rather than failing silently', function () {
    $this->actingAs($this->vendorUser)
        ->post(vendorProductsUrl(), productPayload([
            'images' => [
                UploadedFile::fake()->image('fine.jpg'),
                // Over the 4MB cap.
                UploadedFile::fake()->image('huge.jpg')->size(5000),
            ],
        ]))
        // Keyed per file, which is what the form now renders as "Photo 2: …".
        ->assertSessionHasErrors('images.1');
});

it('still asks for genuine custom fields', function () {
    ProductAttribute::query()->create([
        'category_id' => $this->category->id,
        'key' => 'colour',
        'label' => 'Colour',
        'type' => 'text',
        'is_required' => true,
        'is_active' => true,
    ]);

    // Filtering out built-ins must not filter out the real thing.
    $fields = app(ProductAttributeService::class)->forCategory($this->category);

    expect($fields)->toHaveCount(1)
        ->and($fields->first()->key)->toBe('colour');

    $this->actingAs($this->vendorUser)
        ->post(vendorProductsUrl(), productPayload())
        ->assertSessionHasErrors('attributes.colour');
});
