<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('redirects guests away from the customer dashboard', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('lets an authenticated customer see the customer dashboard', function () {
    $user = User::factory()->create();
    $user->assignRole('Customer');

    $this->actingAs($user)->get('/dashboard')->assertOk();
});

it('lets an authenticated vendor see the vendor dashboard', function () {
    $user = User::factory()->create();
    $user->assignRole('Vendor');

    $this->actingAs($user)->get('/dashboard')->assertOk();
});
