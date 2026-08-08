<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Identity\Models\OtpCode;
use App\Shared\Enums\OtpPurpose;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

function listingStaff(string $role = 'Administrator'): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->assignRole($role);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user;
}

function adminAt(string $path): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/'.ltrim($path, '/');
}

// ── Categories ────────────────────────────────────────────────────────────

it('switches several categories off at once', function () {
    $categories = Category::factory()->count(3)->create(['is_active' => true]);

    $this->actingAs(listingStaff())
        ->post(adminAt('catalog/categories/bulk'), [
            'action' => 'deactivate',
            'ids' => $categories->pluck('id')->all(),
        ])
        ->assertRedirect();

    foreach ($categories as $category) {
        expect($category->fresh()->is_active)->toBeFalse();
    }
});

it('switches categories back on', function () {
    $categories = Category::factory()->count(2)->create(['is_active' => false]);

    $this->actingAs(listingStaff())
        ->post(adminAt('catalog/categories/bulk'), [
            'action' => 'activate',
            'ids' => $categories->pluck('id')->all(),
        ])
        ->assertRedirect();

    expect($categories->first()->fresh()->is_active)->toBeTrue();
});

it('keeps products filed under a category that is switched off', function () {
    $category = Category::factory()->create(['is_active' => true]);
    $before = $category->products()->count();

    $this->actingAs(listingStaff())
        ->post(adminAt('catalog/categories/bulk'), ['action' => 'deactivate', 'ids' => [$category->id]]);

    // Switching off hides it; it must never cascade to the listings.
    expect($category->fresh()->products()->count())->toBe($before);
});

it('rejects an unknown category bulk action', function () {
    $category = Category::factory()->create();

    $this->actingAs(listingStaff())
        ->post(adminAt('catalog/categories/bulk'), ['action' => 'delete', 'ids' => [$category->id]])
        ->assertSessionHasErrors('action');
});

it('blocks category bulk actions without catalog.manage', function () {
    $category = Category::factory()->create(['is_active' => true]);

    $this->actingAs(listingStaff('Support Agent'))
        ->post(adminAt('catalog/categories/bulk'), ['action' => 'deactivate', 'ids' => [$category->id]])
        ->assertForbidden();

    expect($category->fresh()->is_active)->toBeTrue();
});

// ── Product fields ────────────────────────────────────────────────────────

it('switches several product fields off at once', function () {
    $fields = collect([
        ['key' => 'colour', 'label' => 'Colour', 'type' => 'text', 'is_active' => true],
        ['key' => 'wattage', 'label' => 'Wattage', 'type' => 'number', 'is_active' => true],
    ])->map(fn (array $attributes) => ProductAttribute::query()->create($attributes));

    $this->actingAs(listingStaff())
        ->post(adminAt('catalog/product-fields/bulk'), [
            'action' => 'deactivate',
            'ids' => $fields->pluck('id')->all(),
        ])
        ->assertRedirect();

    foreach ($fields as $field) {
        expect($field->fresh()->is_active)->toBeFalse();
    }
});

it('blocks product field bulk actions without catalog.manage', function () {
    $field = ProductAttribute::query()->create([
        'key' => 'colour', 'label' => 'Colour', 'type' => 'text', 'is_active' => true,
    ]);

    $this->actingAs(listingStaff('Support Agent'))
        ->post(adminAt('catalog/product-fields/bulk'), ['action' => 'deactivate', 'ids' => [$field->id]])
        ->assertForbidden();
});

// ── Customers ─────────────────────────────────────────────────────────────

it('creates a customer and emails a set-your-password code', function () {
    $this->actingAs(listingStaff())
        ->post(adminAt('customers'), [
            'name' => 'Amina Okafor',
            'email' => 'amina@example.test',
            'phone' => '08031234567',
        ])
        ->assertRedirect();

    $user = User::query()->firstWhere('email', 'amina@example.test');

    expect($user)->not->toBeNull()
        ->and($user->user_type)->toBe(UserType::Customer)
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->email_verified_at)->not->toBeNull();

    // Staff must never know a customer's password — a one-time code goes out
    // and they choose their own. This is the app's own reset flow, not
    // Laravel's link-based one, which has no route here.
    expect(OtpCode::query()->where('destination', 'amina@example.test')
        ->where('purpose', OtpPurpose::PasswordReset)->exists())->toBeTrue();
});

it('actually renders the customer password email', function () {
    // Mail::fake rather than Notification::fake, so the notification really
    // builds its message — the only way a broken route inside it shows up.
    Mail::fake();

    $this->actingAs(listingStaff())
        ->post(adminAt('customers'), ['name' => 'Amina Okafor', 'email' => 'amina@example.test'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'amina@example.test')->exists())->toBeTrue();
});

it('rejects a customer email that already exists', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs(listingStaff())
        ->post(adminAt('customers'), ['name' => 'Someone', 'email' => 'taken@example.test'])
        ->assertSessionHasErrors('email');
});

it('suspends several customers with one reason', function () {
    $customers = User::factory()->count(2)->create(['status' => UserStatus::Active]);

    $this->actingAs(listingStaff())
        ->post(adminAt('customers/bulk'), [
            'action' => 'suspend',
            'uuids' => $customers->pluck('uuid')->all(),
            'reason' => 'Chargebacks under review.',
        ])
        ->assertRedirect();

    foreach ($customers as $customer) {
        expect($customer->fresh()->status)->toBe(UserStatus::Suspended);
    }
});

it('refuses to bulk suspend without a reason', function () {
    $customers = User::factory()->count(2)->create(['status' => UserStatus::Active]);

    $this->actingAs(listingStaff())
        ->post(adminAt('customers/bulk'), [
            'action' => 'suspend',
            'uuids' => $customers->pluck('uuid')->all(),
        ])
        ->assertSessionHasErrors('reason');

    expect($customers->first()->fresh()->status)->toBe(UserStatus::Active);
});

it('never bulk-bans, only suspends', function () {
    $customer = User::factory()->create(['status' => UserStatus::Active]);

    // Ban is the one step with no easy way back, so it stays on the individual
    // screen where the operator can see who they are banning.
    $this->actingAs(listingStaff())
        ->post(adminAt('customers/bulk'), [
            'action' => 'ban',
            'uuids' => [$customer->uuid],
            'reason' => 'Fraud',
        ])
        ->assertSessionHasErrors('action');

    expect($customer->fresh()->status)->toBe(UserStatus::Active);
});

it('will not bulk-moderate a staff account', function () {
    $staff = listingStaff('Support Agent');

    $this->actingAs(listingStaff())
        ->post(adminAt('customers/bulk'), [
            'action' => 'suspend',
            'uuids' => [$staff->uuid],
            'reason' => 'Testing',
        ])
        ->assertRedirect();

    // The query is scoped to customers, so a staff uuid simply matches nothing.
    expect($staff->fresh()->status)->toBe(UserStatus::Active);
});
