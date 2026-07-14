<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

it('seeds every core role from the Developer Guidelines', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $roles = Role::pluck('name');

    expect($roles)->toContain(
        'Customer',
        'Vendor',
        'Super Administrator',
        'Administrator',
        'Support Agent',
        'Logistics Personnel',
        'Finance Officer',
    );
});

it('only grants Logistics Personnel the delivery.update permission', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $role = Role::findByName('Logistics Personnel', 'web');

    expect($role->permissions->pluck('name')->all())->toBe(['delivery.update']);
});
