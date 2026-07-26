<?php

use App\Models\User;
use App\Modules\Admin\Services\UserModerationService;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Sprint 9 QA: user suspension/ban and the session revocation they trigger.
 * The revocation itself is EnsureUserIsActive (Sprint 2) — these tests
 * confirm the new admin action correctly drives that existing enforcement,
 * not that the enforcement exists.
 */
beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function moderationAdminUrl(string $path): string
{
    return 'http://'.config('app.admin_domain').'/customers/'.ltrim($path, '/');
}

function moderationStaff(string $role): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->assignRole($role);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user;
}

function moderationCustomer(): User
{
    $user = User::factory()->create(['user_type' => UserType::Customer]);
    $user->assignRole('Customer');

    return $user;
}

it('suspends a customer, records the reason, and audits it', function () {
    $customer = moderationCustomer();
    $admin = moderationStaff('Administrator');

    $this->actingAs($admin)
        ->post(moderationAdminUrl($customer->uuid.'/suspend'), ['reason' => 'Chargeback dispute under review.'])
        ->assertRedirect();

    $customer->refresh();

    expect($customer->status)->toBe(UserStatus::Suspended)
        ->and($customer->status_reason)->toBe('Chargeback dispute under review.')
        ->and($customer->status_changed_by)->toBe($admin->id);

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $admin->id,
        'action' => 'admin.user_suspended',
    ]);
});

it('bans a customer', function () {
    $customer = moderationCustomer();
    $admin = moderationStaff('Administrator');

    $this->actingAs($admin)
        ->post(moderationAdminUrl($customer->uuid.'/ban'), ['reason' => 'Confirmed fraud.'])
        ->assertRedirect();

    expect($customer->refresh()->status)->toBe(UserStatus::Banned);
});

it('ends a suspended customer\'s session on their next request', function () {
    // Suspended via the service directly (the HTTP endpoint itself is
    // covered by the "suspends a customer" test above) — chaining an
    // admin-subdomain request and a main-domain actingAs() request in one
    // test leaves route()/auth state pointed at the admin host otherwise.
    $customer = moderationCustomer();
    app(UserModerationService::class)->suspend($customer, moderationStaff('Administrator'), 'Policy violation.');

    $this->actingAs($customer)
        ->get('/dashboard')
        ->assertRedirect('/login');

    $this->assertGuest();
});

it('blocks a suspended customer from logging back in', function () {
    $customer = moderationCustomer();
    $customer->forceFill(['password' => bcrypt('Password123')])->save();
    app(UserModerationService::class)->suspend($customer, moderationStaff('Administrator'), 'Policy violation.');

    $this->post('/login', ['identifier' => $customer->email, 'password' => 'Password123'])
        ->assertSessionHasErrors();

    $this->assertGuest();
});

it('reactivates a suspended customer', function () {
    $customer = moderationCustomer();
    $admin = moderationStaff('Administrator');

    $this->actingAs($admin)->post(moderationAdminUrl($customer->uuid.'/suspend'), ['reason' => 'Temporary hold.']);

    $this->actingAs($admin)
        ->post(moderationAdminUrl($customer->uuid.'/reactivate'))
        ->assertRedirect();

    expect($customer->refresh()->status)->toBe(UserStatus::Active)
        ->and($customer->refresh()->status_reason)->toBeNull();
});

it('requires a reason to suspend', function () {
    $customer = moderationCustomer();

    $this->actingAs(moderationStaff('Administrator'))
        ->post(moderationAdminUrl($customer->uuid.'/suspend'), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($customer->refresh()->status)->toBe(UserStatus::Active);
});

it('refuses to moderate a staff account', function () {
    $otherAdmin = moderationStaff('Support Agent');
    $admin = moderationStaff('Administrator');

    $this->actingAs($admin)
        ->post(moderationAdminUrl($otherAdmin->uuid.'/suspend'), ['reason' => 'Testing.'])
        ->assertSessionHasErrors('user');

    expect($otherAdmin->refresh()->status)->toBe(UserStatus::Active);
});

it('denies moderation to a role without customers.suspend', function () {
    $customer = moderationCustomer();

    $this->actingAs(moderationStaff('Logistics Personnel'))
        ->post(moderationAdminUrl($customer->uuid.'/suspend'), ['reason' => 'Trying anyway.'])
        ->assertForbidden();

    expect($customer->refresh()->status)->toBe(UserStatus::Active);
});

it('lists and searches customers on the management index', function () {
    User::factory()->create(['user_type' => UserType::Customer, 'name' => 'Ada Lovelace']);
    User::factory()->create(['user_type' => UserType::Customer, 'name' => 'Grace Hopper']);

    $response = $this->actingAs(moderationStaff('Administrator'))
        ->get('http://'.config('app.admin_domain').'/customers?q=Ada')
        ->assertOk();

    $names = collect($response->viewData('page')['props']['users']['data'])->pluck('name');

    expect($names)->toContain('Ada Lovelace')
        ->and($names)->not->toContain('Grace Hopper');
});
