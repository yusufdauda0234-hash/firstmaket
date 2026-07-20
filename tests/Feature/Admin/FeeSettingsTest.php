<?php

use App\Models\User;
use App\Modules\Catalog\Models\VendorFeeSetting;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Sprint 3: admin vendor posting-fee settings page, gated by
 * vendor_fees.manage on the admin subdomain.
 */
beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function feeSettingsUrl(): string
{
    return 'http://'.config('app.admin_domain').'/settings/fees';
}

function feeStaff(string $role): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->assignRole($role);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user;
}

it('shows the fee settings page to an Administrator', function () {
    $this->actingAs(feeStaff('Administrator'))
        ->get(feeSettingsUrl())
        ->assertOk();
});

it('denies fee settings to a Support Agent', function () {
    $this->actingAs(feeStaff('Support Agent'))
        ->get(feeSettingsUrl())
        ->assertForbidden();
});

it('updates posting mode and tier fees with an audit trail', function () {
    $admin = feeStaff('Administrator');

    $this->actingAs($admin)
        ->post(feeSettingsUrl(), [
            'posting_mode' => 'paid',
            'basic_fee_naira' => 750,
            'premium_fee_naira' => 2500,
            'featured_fee_naira' => 6000,
        ])
        ->assertRedirect(feeSettingsUrl());

    $settings = VendorFeeSetting::current()->refresh();

    expect($settings->posting_mode)->toBe('paid')
        ->and($settings->basic_fee_kobo)->toBe(75000)
        ->and($settings->premium_fee_kobo)->toBe(250000)
        ->and($settings->featured_fee_kobo)->toBe(600000)
        ->and($settings->updated_by)->toBe($admin->id);

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $admin->id,
        'action' => 'admin.vendor_fees_updated',
    ]);
});

it('rejects an invalid posting mode', function () {
    $this->actingAs(feeStaff('Administrator'))
        ->post(feeSettingsUrl(), [
            'posting_mode' => 'donations',
            'basic_fee_naira' => 500,
            'premium_fee_naira' => 2000,
            'featured_fee_naira' => 5000,
        ])
        ->assertSessionHasErrors('posting_mode');
});
