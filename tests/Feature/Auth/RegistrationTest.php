<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('registers a new customer and assigns the Customer role', function () {
    $response = $this->post('/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'phone' => '+2348012345678',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

    expect($user->hasRole('Customer'))->toBeTrue();
    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard'));
});

it('rejects registration with a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $response = $this->from('/register')->post('/register', [
        'name' => 'Ada Lovelace',
        'email' => 'taken@example.com',
        'phone' => '+2348012345678',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect('/register');
    $response->assertSessionHasErrors('email');
});
