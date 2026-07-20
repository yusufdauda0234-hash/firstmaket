<?php

use App\Models\User;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function adminVendorsUrl(string $path = ''): string
{
    return 'http://'.config('app.admin_domain').'/vendors'.($path === '' ? '' : '/'.ltrim($path, '/'));
}

function staffUser(string $role): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->assignRole($role);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user;
}

function pendingVendor(): VendorProfile
{
    $vendorUser = User::factory()->create(['user_type' => UserType::Vendor]);
    $vendorUser->assignRole('Vendor');

    return VendorProfile::query()->create([
        'user_id' => $vendorUser->id,
        'business_name' => 'Ada Electronics Ltd',
        'contact_name' => 'Ada Lovelace',
        'status' => VendorStatus::Pending,
    ]);
}

it('shows the pending vendor queue to an Administrator', function () {
    $vendor = pendingVendor();

    $this->actingAs(staffUser('Administrator'))
        ->get(adminVendorsUrl())
        ->assertOk();
});

it('denies the vendor queue to Logistics Personnel', function () {
    $this->actingAs(staffUser('Logistics Personnel'))
        ->get(adminVendorsUrl())
        ->assertForbidden();
});

it('lets an Administrator approve a pending vendor', function () {
    $vendor = pendingVendor();
    $admin = staffUser('Administrator');

    $this->actingAs($admin)
        ->post(adminVendorsUrl($vendor->uuid.'/approve'))
        ->assertRedirect();

    $vendor->refresh();

    expect($vendor->status)->toBe(VendorStatus::Approved)
        ->and($vendor->approved_by)->toBe($admin->id)
        ->and($vendor->approved_at)->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $admin->id,
        'action' => 'vendor.approved',
    ]);
});

it('lets a Super Administrator approve a pending vendor', function () {
    $vendor = pendingVendor();

    $this->actingAs(staffUser('Super Administrator'))
        ->post(adminVendorsUrl($vendor->uuid.'/approve'))
        ->assertRedirect();

    expect($vendor->refresh()->status)->toBe(VendorStatus::Approved);
});

it('denies approval to a Support Agent', function () {
    $vendor = pendingVendor();

    $this->actingAs(staffUser('Support Agent'))
        ->post(adminVendorsUrl($vendor->uuid.'/approve'))
        ->assertForbidden();

    expect($vendor->refresh()->status)->toBe(VendorStatus::Pending);
});

it('rejects a vendor with a reason', function () {
    $vendor = pendingVendor();

    $this->actingAs(staffUser('Administrator'))
        ->post(adminVendorsUrl($vendor->uuid.'/reject'), ['reason' => 'CAC document is illegible.'])
        ->assertRedirect();

    $vendor->refresh();

    expect($vendor->status)->toBe(VendorStatus::Rejected)
        ->and($vendor->rejection_reason)->toBe('CAC document is illegible.');
});

it('requires a reason to reject', function () {
    $vendor = pendingVendor();

    $this->actingAs(staffUser('Administrator'))
        ->post(adminVendorsUrl($vendor->uuid.'/reject'), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($vendor->refresh()->status)->toBe(VendorStatus::Pending);
});

it('refuses to re-review an already approved vendor', function () {
    $vendor = pendingVendor();
    $admin = staffUser('Administrator');

    $this->actingAs($admin)->post(adminVendorsUrl($vendor->uuid.'/approve'));

    $this->actingAs($admin)
        ->post(adminVendorsUrl($vendor->uuid.'/reject'), ['reason' => 'Changed my mind.'])
        ->assertSessionHasErrors('status');

    expect($vendor->refresh()->status)->toBe(VendorStatus::Approved);
});
