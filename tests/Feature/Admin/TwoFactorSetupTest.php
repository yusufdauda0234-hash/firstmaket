<?php

use App\Models\User;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('renders the 2FA setup screen with a scannable QR code', function () {
    $admin = User::factory()->create(['user_type' => UserType::Staff]);
    $admin->assignRole('Administrator');

    $response = $this->actingAs($admin)
        ->get('http://'.config('app.admin_domain').'/two-factor/setup');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Auth/TwoFactorSetup')
        ->has('secret')
        ->has('otpAuthUrl')
        ->where('qrCodeSvg', fn ($svg) => str_starts_with($svg, '<svg')));
});
