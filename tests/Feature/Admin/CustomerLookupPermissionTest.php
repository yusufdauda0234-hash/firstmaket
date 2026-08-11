<?php

use App\Models\User;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Who may see what a customer has saved.
 *
 * `savings.view` and `plans.view` were seeded from the start but never
 * checked anywhere, which meant an admin granting them was granting nothing
 * and an admin revoking them was revoking nothing. They now gate the
 * financial context on the support lookup — the one screen that shows a
 * customer's balance and what they are saving towards.
 *
 * Support Agent is granted both in the seeder, so the job is unchanged from
 * before. What is new is that an admin can now build a support role without
 * them, which was previously impossible.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->customer = User::factory()->create(['user_type' => UserType::Customer]);
});

function lookupStaff(string $role): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    $user->assignRole($role);

    return $user;
}

it('shows the savings context to a support agent, exactly as before', function () {
    $this->actingAs(lookupStaff('Support Agent'))
        ->get(adminUrl('/support/lookup?customer='.$this->customer->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Support/Lookup')
            ->where('customer.canSeeFinancials', true));
});

it('hides the savings context from staff without savings.view', function () {
    // The scenario this feature exists for: an admin builds a support role
    // that deliberately excludes financial visibility. Revoked from the role,
    // not the user — that is where the permission is granted.
    $agent = lookupStaff('Support Agent');
    \Spatie\Permission\Models\Role::findByName('Support Agent', 'web')
        ->revokePermissionTo('savings.view');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($agent)
        ->get(adminUrl('/support/lookup?customer='.$this->customer->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Support/Lookup')
            ->where('customer.canSeeFinancials', false)
            // Not merely hidden in the UI — the figure never leaves the server.
            ->where('customer.savingsBalanceKobo', null)
            ->has('customer.savingsGoals', 0));
});

it('still lets an agent without savings.view do the rest of their job', function () {
    // The scenario this feature exists for: an admin builds a support role
    // that deliberately excludes financial visibility. Revoked from the role,
    // not the user — that is where the permission is granted.
    $agent = lookupStaff('Support Agent');
    \Spatie\Permission\Models\Role::findByName('Support Agent', 'web')
        ->revokePermissionTo('savings.view');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($agent)
        ->get(adminUrl('/support/lookup?customer='.$this->customer->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // The customer, their orders and their tickets are all still there.
            ->has('customer.name')
            ->has('customer.orders')
            ->has('customer.tickets'));
});

it('grants the permission to the roles that already had this access', function () {
    expect(lookupStaff('Support Agent')->can('savings.view'))->toBeTrue()
        ->and(lookupStaff('Administrator')->can('savings.view'))->toBeTrue()
        ->and(lookupStaff('Finance Officer')->can('savings.view'))->toBeTrue()
        // Logistics never had a reason to see it and still does not.
        ->and(lookupStaff('Logistics Personnel')->can('savings.view'))->toBeFalse();
});
