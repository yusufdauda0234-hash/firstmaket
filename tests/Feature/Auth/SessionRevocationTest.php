<?php

use App\Models\User;
use App\Shared\Enums\UserStatus;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('logs out a user the moment they are suspended', function () {
    $user = User::factory()->create();
    $user->assignRole('Customer');

    $this->actingAs($user)->get(route('wallet.index'))->assertOk();

    $user->forceFill(['status' => UserStatus::Suspended])->save();

    $this->get(route('wallet.index'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('logs out a banned user and deletes their API tokens', function () {
    $user = User::factory()->create();
    $user->assignRole('Customer');
    $user->createToken('mobile');

    $user->forceFill(['status' => UserStatus::Banned])->save();

    $this->actingAs($user)->get(route('wallet.index'))->assertRedirect(route('login'));

    $this->assertGuest();
    expect($user->tokens()->count())->toBe(0);
});

it('blocks a suspended user from logging in again', function () {
    $user = User::factory()->create(['status' => UserStatus::Suspended]);
    $user->assignRole('Customer');

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});
