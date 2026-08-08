<?php

use App\Models\User;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VendorStatus;
use App\Shared\Middleware\EnsureVendorApproved;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * The Vendor Center before anybody has said yes.
 *
 * Approval used to be checked inside product management and nowhere else, so
 * a pending vendor signed in to a full navigation and discovered which pages
 * worked by clicking them — orders and earnings opened, listings did not.
 * That reads as a broken site rather than a queue they are waiting in.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

function vendorAt(VendorStatus $status): User
{
    $user = User::factory()->create(['user_type' => UserType::Vendor, 'phone_verified_at' => now()]);
    $user->assignRole('Vendor');

    VendorProfile::factory()->create([
        'user_id' => $user->id,
        'status' => $status,
    ]);

    return $user;
}

function centre(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.vendor_domain')).'/'.ltrim($path, '/');
}

/** Every page approval is supposed to gate. */
dataset('gated pages', [
    'products' => ['products'],
    'orders' => ['orders'],
    'earnings' => ['earnings'],
]);

// ── Pending ─────────────────────────────────────────────────────────────

it('turns a pending vendor away from every selling page', function (string $path) {
    $this->actingAs(vendorAt(VendorStatus::Pending))
        ->get(centre($path))
        ->assertRedirect(route('vendor.dashboard'));
})->with('gated pages');

it('still lets a pending vendor reach their dashboard', function () {
    // It is where they are told what is happening. Redirecting this one too
    // would be a loop.
    $this->actingAs(vendorAt(VendorStatus::Pending))
        ->get(centre('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Vendor/Dashboard')
            ->where('vendorStatus', 'pending'));
});

it('refuses a write outright rather than redirecting it', function () {
    // A form post that quietly lands on the dashboard looks like it worked.
    $this->actingAs(vendorAt(VendorStatus::Pending))
        ->post(centre('products'), ['name' => 'Sneaky listing'])
        ->assertForbidden();
});

it('hides the selling nav from a pending vendor', function () {
    // The nav is built from this. Offering links the middleware then refuses
    // is worse than offering none.
    $this->actingAs(vendorAt(VendorStatus::Pending))
        ->get(centre('dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.user.vendorStatus', 'pending'));
});

// ── The other ways of not being approved ────────────────────────────────

it('turns a rejected vendor away too', function (string $path) {
    $this->actingAs(vendorAt(VendorStatus::Rejected))
        ->get(centre($path))
        ->assertRedirect(route('vendor.dashboard'));
})->with('gated pages');

it('turns a suspended vendor away', function (string $path) {
    $this->actingAs(vendorAt(VendorStatus::Suspended))
        ->get(centre($path))
        ->assertRedirect(route('vendor.dashboard'));
})->with('gated pages');

it('says which situation the vendor is in, not just "not approved"', function () {
    // A rejected vendor told "not approved" waits for an email that is never
    // coming. Each status is a different thing to do next.
    $pending = EnsureVendorApproved::reasonFor(VendorStatus::Pending);
    $rejected = EnsureVendorApproved::reasonFor(VendorStatus::Rejected);
    $suspended = EnsureVendorApproved::reasonFor(VendorStatus::Suspended);

    expect($pending)->toContain('being reviewed')
        ->and($rejected)->toContain('not approved')
        ->and($suspended)->toContain('suspended')
        ->and([$pending, $rejected, $suspended])->toHaveCount(3)
        ->and(count(array_unique([$pending, $rejected, $suspended])))->toBe(3);
});

// ── Approved ────────────────────────────────────────────────────────────

it('opens everything once the vendor is approved', function (string $path) {
    $this->actingAs(vendorAt(VendorStatus::Approved))
        ->get(centre($path))
        ->assertOk();
})->with('gated pages');

it('shows the selling nav to an approved vendor', function () {
    $this->actingAs(vendorAt(VendorStatus::Approved))
        ->get(centre('dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.user.vendorStatus', 'approved'));
});

// ── Onboarding is not gated ─────────────────────────────────────────────

it('lets a pending vendor still verify their phone', function () {
    // Verifying is part of getting approved, not something that waits on it.
    $user = User::factory()->create(['user_type' => UserType::Vendor]);
    $user->assignRole('Vendor');
    VendorProfile::factory()->create(['user_id' => $user->id, 'status' => VendorStatus::Pending]);

    $this->actingAs($user)
        ->post(centre('verify-phone/send'), ['phone' => '08031234567'])
        ->assertSessionHasNoErrors();
});
