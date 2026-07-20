<?php

use App\Models\User;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function adminPortalUrl(string $path = ''): string
{
    return 'http://'.config('app.admin_domain').'/'.ltrim($path, '/');
}

it('rejects a customer login on the staff portal with a friendly message', function () {
    $customer = User::factory()->create();
    $customer->assignRole('Customer');

    $response = $this->from(adminPortalUrl('login'))->post(adminPortalUrl('login'), [
        'email' => $customer->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('staff only');
    $this->assertGuest();
});

it('rejects a vendor login on the staff portal', function () {
    $vendor = User::factory()->create(['user_type' => UserType::Vendor]);
    $vendor->assignRole('Vendor');

    $this->post(adminPortalUrl('login'), [
        'email' => $vendor->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('rejects a staff login on the customer site with a friendly message', function () {
    $admin = User::factory()->create(['user_type' => UserType::Staff]);
    $admin->assignRole('Administrator');

    $response = $this->post('/login', [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('staff portal');
    $this->assertGuest();
});

it('still lets staff log in on the staff portal', function () {
    $admin = User::factory()->create(['user_type' => UserType::Staff]);
    $admin->assignRole('Administrator');

    $this->post(adminPortalUrl('login'), [
        'email' => $admin->email,
        'password' => 'password',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($admin);
});

it('still lets customers log in on the main site', function () {
    $customer = User::factory()->create();
    $customer->assignRole('Customer');

    $this->post('/login', [
        'email' => $customer->email,
        'password' => 'password',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($customer);
});

it('logs out a customer session that lands on the staff portal, with a friendly message', function () {
    $customer = User::factory()->create();
    $customer->assignRole('Customer');

    $response = $this->actingAs($customer)->get(adminPortalUrl());

    $response->assertRedirect(route('admin.login'));
    $response->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('staff only');
    $this->assertGuest();
});

it('logs out a stale staff session on the customer site instead of showing 403s', function () {
    $admin = User::factory()->create(['user_type' => UserType::Staff]);
    $admin->assignRole('Administrator');

    $response = $this->actingAs($admin)->get(route('dashboard'));

    $response->assertRedirect(route('login'));
    expect(session('errors')->first('email'))->toContain('staff portal');
    $this->assertGuest();
});

it('does not answer customer registration on the admin subdomain', function () {
    $this->get(adminPortalUrl('register'))
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page->component('Error')->where('status', 404));

    $this->post(adminPortalUrl('register'), [
        'name' => 'Sneaky Person',
        'email' => 'sneaky@example.com',
        'phone' => '+2348099999999',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    expect(User::query()->where('email', 'sneaky@example.com')->exists())->toBeFalse();
});

it('does not answer vendor registration or the customer dashboard on the admin subdomain', function () {
    $this->get(adminPortalUrl('vendor/register'))->assertNotFound();
    $this->get(adminPortalUrl('dashboard'))->assertNotFound();
});

it('shows a friendly not-found page for unknown URLs', function () {
    $this->get('/definitely-not-a-page')
        ->assertNotFound()
        ->assertInertia(fn ($page) => $page->component('Error')->where('status', 404));
});
