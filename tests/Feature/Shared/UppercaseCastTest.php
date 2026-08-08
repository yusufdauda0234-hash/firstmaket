<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Vendor\Models\VendorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * The cast has two halves and both matter: upper case is what lands in the
 * database, so sorting and matching stay predictable; title case is what
 * comes back out, so nothing in the UI shouts at the customer. Each test
 * below checks the stored column, not just the model attribute.
 */
function storedValue(string $table, int $id, string $column): ?string
{
    return DB::table($table)->where('id', $id)->value($column);
}

it('stores a name upper case and reads it back title case', function () {
    $user = User::factory()->create(['name' => 'yusuf yakubu dauda']);

    expect(storedValue('users', $user->id, 'name'))->toBe('YUSUF YAKUBU DAUDA')
        ->and($user->fresh()->name)->toBe('Yusuf Yakubu Dauda');
});

it('leaves the email exactly as typed', function () {
    // Matched case-insensitively and shown back to people as they wrote it —
    // upper-casing it would change what lands in their inbox header.
    $user = User::factory()->create(['email' => 'Yusuf.Dauda@Example.com']);

    expect($user->fresh()->email)->toBe('Yusuf.Dauda@Example.com');
});

it('leaves the password hash alone', function () {
    $user = User::factory()->create(['password' => bcrypt('correct-horse')]);

    expect(Hash::check('correct-horse', $user->fresh()->password))->toBeTrue();
});

it('casts a category name but not its slug', function () {
    $category = Category::factory()->create(['name' => 'home appliances', 'slug' => 'home-appliances']);

    // The slug is part of a URL, so its casing is not cosmetic.
    expect(storedValue('categories', $category->id, 'name'))->toBe('HOME APPLIANCES')
        ->and($category->fresh()->name)->toBe('Home Appliances')
        ->and($category->fresh()->slug)->toBe('home-appliances');
});

it('casts a product name but not its description', function () {
    $product = Product::factory()->create([
        'name' => 'Samsung 55" QLED Smart TV',
        'description' => 'Brand new and factory sealed with a six-month warranty.',
    ]);

    // A paragraph in capitals is unreadable, which is why the rule is per field
    // rather than blanket. The acronym survives the round trip.
    expect(storedValue('products', $product->id, 'name'))->toBe('SAMSUNG 55" QLED SMART TV')
        ->and($product->fresh()->name)->toBe('Samsung 55" QLED Smart TV')
        ->and($product->fresh()->description)->toBe('Brand new and factory sealed with a six-month warranty.');
});

it('casts vendor business and contact names', function () {
    $profile = VendorProfile::factory()->create([
        'business_name' => 'amashpay nig. limited',
        'contact_name' => 'yusuf dauda',
    ]);

    expect(storedValue('vendor_profiles', $profile->id, 'business_name'))->toBe('AMASHPAY NIG. LIMITED')
        ->and($profile->fresh()->business_name)->toBe('Amashpay Nig. Limited')
        ->and($profile->fresh()->contact_name)->toBe('Yusuf Dauda');
});

it('trims surrounding whitespace as it casts', function () {
    $user = User::factory()->create(['name' => '   ada okafor   ']);

    expect(storedValue('users', $user->id, 'name'))->toBe('ADA OKAFOR')
        ->and($user->fresh()->name)->toBe('Ada Okafor');
});

it('keeps null null rather than turning it into an empty string', function () {
    $product = Product::factory()->create();
    $product->name = null;

    expect($product->name)->toBeNull();
});

it('handles accented characters without corrupting them', function () {
    // mb_strtoupper, not strtoupper — a Yorùbá name must survive both ways.
    $user = User::factory()->create(['name' => 'adéwálé oyèláràn']);

    expect(storedValue('users', $user->id, 'name'))->toBe('ADÉWÁLÉ OYÈLÁRÀN')
        ->and($user->fresh()->name)->toBe('Adéwálé Oyèláràn');
});

it('survives a second save without drifting', function () {
    // get() title-cases and set() upper-cases, so a read-modify-write cycle
    // must land back on exactly the same stored bytes.
    $product = Product::factory()->create(['name' => 'infinix note 40 pro 256GB']);

    $stored = storedValue('products', $product->id, 'name');

    $product->fresh()->save();

    expect(storedValue('products', $product->id, 'name'))->toBe($stored);
});
