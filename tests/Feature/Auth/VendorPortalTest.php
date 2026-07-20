<?php

use App\Models\User;
use App\Modules\Vendor\Models\VendorProfile;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Vendor Center portal separation, mirroring the admin subdomain isolation:
 * vendor routes live only on the vendors subdomain, only vendor accounts
 * may sign in or hold a session there, and customer routes 404 on it.
 */
beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function vendorPortalUrl(string $path = ''): string
{
    return 'http://'.config('app.vendor_domain').'/'.ltrim($path, '/');
}

function portalVendor(): User
{
    $profile = VendorProfile::factory()->approved()->create();
    $user = $profile->user;
    $user->assignRole('Vendor');

    return $user;
}

it('redirects guests on the vendor portal to the vendor login', function () {
    $this->get(vendorPortalUrl('dashboard'))->assertRedirect(route('vendor.login'));
});

it('lets a vendor sign in on the vendor portal and land on the dashboard', function () {
    $vendor = portalVendor();

    $this->post(vendorPortalUrl('login'), [
        'identifier' => $vendor->email,
        'password' => 'password',
    ])->assertRedirect(route('vendor.dashboard'));

    $this->assertAuthenticatedAs($vendor);
});

it('rejects a customer login on the vendor portal with a friendly message', function () {
    $customer = User::factory()->create();
    $customer->assignRole('Customer');

    $response = $this->post(vendorPortalUrl('login'), [
        'identifier' => $customer->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('identifier');
    expect(session('errors')->first('identifier'))->toContain('vendors only');
    $this->assertGuest();
});

it('rejects a staff login on the vendor portal', function () {
    $staff = User::factory()->staff()->create();
    $staff->assignRole('Administrator');

    $this->post(vendorPortalUrl('login'), [
        'identifier' => $staff->email,
        'password' => 'password',
    ])->assertSessionHasErrors('identifier');

    $this->assertGuest();
});

it('logs a customer session out of the vendor portal', function () {
    $customer = User::factory()->create();
    $customer->assignRole('Customer');

    $this->actingAs($customer)
        ->get(vendorPortalUrl('dashboard'))
        ->assertRedirect(route('vendor.login'));

    $this->assertGuest();
});

it('shows the vendor dashboard to a signed-in vendor', function () {
    $this->actingAs(portalVendor())
        ->get(vendorPortalUrl('dashboard'))
        ->assertOk();
});

it('404s customer routes on the vendor portal', function () {
    $this->get(vendorPortalUrl('register'))->assertNotFound();
    $this->get(vendorPortalUrl())->assertRedirect(); // "/" redirects into the portal, not the marketplace
});

it('no longer serves vendor product management on the main domain', function () {
    $vendor = portalVendor();

    $this->actingAs($vendor)->get('/vendor/products')->assertNotFound();
});

it('still serves vendor product management on the vendor portal', function () {
    $vendor = portalVendor();

    $this->actingAs($vendor)->get(vendorPortalUrl('products'))->assertOk();
});
