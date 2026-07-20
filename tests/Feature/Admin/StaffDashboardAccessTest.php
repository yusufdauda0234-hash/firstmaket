<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function adminUrl(string $path = ''): string
{
    return 'http://'.config('app.admin_domain').'/'.ltrim($path, '/');
}

it('redirects guests away from the admin dashboard', function () {
    $this->get(adminUrl())->assertRedirect(adminUrl('login'));
});

it('logs a Customer out of the staff portal and sends them to the admin login', function () {
    $user = User::factory()->create();
    $user->assignRole('Customer');

    $this->actingAs($user)->get(adminUrl())->assertRedirect(route('admin.login'));
    $this->assertGuest();
});

it('requires 2FA enrollment before an Administrator reaches the dashboard', function () {
    $user = User::factory()->staff()->create();
    $user->assignRole('Administrator');

    $this->actingAs($user)
        ->get(adminUrl())
        ->assertRedirect(route('admin.two-factor.setup'));
});

it('lets a Support Agent reach the staff dashboard without 2FA', function () {
    $user = User::factory()->staff()->create();
    $user->assignRole('Support Agent');

    $this->actingAs($user)->get(adminUrl())->assertOk();
});

it('lets an Administrator reach the dashboard once 2FA is confirmed', function () {
    $user = User::factory()->staff()->create();
    $user->assignRole('Administrator');
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->actingAs($user)->get(adminUrl())->assertOk();
});
