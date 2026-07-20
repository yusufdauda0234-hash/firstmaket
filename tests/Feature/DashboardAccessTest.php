<?php

use App\Models\User;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('redirects guests away from the customer dashboard', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('sends an authenticated customer home (home is their dashboard)', function () {
    $user = User::factory()->create();
    $user->assignRole('Customer');

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('home'));
});

it('sends an authenticated vendor to the Vendor Center dashboard', function () {
    $user = User::factory()->create(['user_type' => UserType::Vendor]);
    $user->assignRole('Vendor');

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('vendor.dashboard'));
});
