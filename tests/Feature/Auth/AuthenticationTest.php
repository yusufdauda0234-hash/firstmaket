<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('lets a customer log in with the correct credentials', function () {
    $user = User::factory()->create();
    $user->assignRole('Customer');

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    // Customers land back on the marketplace (home is their dashboard).
    $response->assertRedirect(route('home'));
});

it('rejects an incorrect password', function () {
    $user = User::factory()->create();
    $user->assignRole('Customer');

    $response = $this->from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
    $response->assertRedirect('/login');
});

it('records a login event with device and IP metadata', function () {
    $user = User::factory()->create();
    $user->assignRole('Customer');

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertDatabaseHas('login_events', [
        'user_id' => $user->id,
        'is_new_device' => true,
    ]);
});
