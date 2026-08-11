<?php

use App\Models\User;
use App\Modules\Admin\Services\RoleService;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function roleManager(string $role = 'Super Administrator'): User
{
    $user = User::factory()->create([
        'user_type' => UserType::Staff,
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

it('creates a role and assigns only selected permissions', function () {
    $role = app(RoleService::class)->create(
        roleManager(),
        'Warehouse Coordinator',
        'Handles warehouse movement',
        ['orders.manage', 'delivery.update'],
    );

    expect($role->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(['orders.manage', 'delivery.update']);
});

it('prevents a non-super administrator from granting permissions they do not hold', function () {
    $actor = roleManager('Administrator');
    $actor->givePermissionTo('roles.manage');

    expect(fn () => app(RoleService::class)->create(
        $actor,
        'Unsafe Role',
        null,
        ['affiliate_payouts.approve'],
    ))->toThrow(ValidationException::class);
});

it('keeps system role names immutable and system roles undeletable', function () {
    $service = app(RoleService::class);
    $administrator = Role::query()->where('name', 'Administrator')->firstOrFail();

    expect(fn () => $service->update(
        roleManager(),
        $administrator,
        'Renamed Administrator',
        null,
        [],
    ))->toThrow(ValidationException::class);

    expect(fn () => $service->delete(roleManager(), $administrator))
        ->toThrow(ValidationException::class);
});

it('does not allow a non-super administrator to manage roles', function () {
    expect(fn () => app(RoleService::class)->create(
        roleManager('Support Agent'),
        'Support Only',
        null,
        ['support.manage'],
    ))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('rejects renaming a role to a name another role already has', function () {
    $service = app(RoleService::class);
    $actor = roleManager();
    $service->create($actor, 'Warehouse Coordinator', null, ['orders.manage']);
    $other = $service->create($actor, 'Returns Coordinator', null, ['orders.manage']);

    expect(fn () => $service->update($actor, $other, 'Warehouse Coordinator', null, ['orders.manage']))
        ->toThrow(ValidationException::class);
});

it('lets an actor grant a permission it holds directly, even one the old hardcoded list reserved for Super Administrator', function () {
    // staff.manage used to be blocked outright by a hardcoded
    // Super-Administrator-only list, regardless of whether the actor held
    // it. The only rule now is "you may grant what you hold" — consistent
    // with every other escalation check in this service.
    $actor = roleManager('Administrator');
    $actor->givePermissionTo('roles.manage', 'staff.manage');

    $role = app(RoleService::class)->create(
        $actor,
        'Staff Onboarding Lead',
        null,
        ['staff.manage'],
    );

    expect($role->permissions->pluck('name')->all())->toBe(['staff.manage']);
});

it('refuses to delete a role that staff are still assigned to', function () {
    $service = app(RoleService::class);
    $actor = roleManager();
    $role = $service->create($actor, 'Warehouse Coordinator', null, ['orders.manage']);

    $staffMember = User::factory()->create(['user_type' => UserType::Staff]);
    $staffMember->assignRole($role);

    expect(fn () => $service->delete($actor, $role))->toThrow(ValidationException::class);

    // Nothing was silently stripped from the staff account.
    expect($staffMember->fresh()->hasRole('Warehouse Coordinator'))->toBeTrue();
});
