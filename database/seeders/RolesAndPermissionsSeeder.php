<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Core roles and permission groups from
 * docs/FirstMaket_Developer_Guidelines.md section 8. Super Administrator
 * is granted every ability via a Gate::before hook in AppServiceProvider
 * rather than an explicit permission list, so newly added permissions are
 * automatically covered without a reseed.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    private const PERMISSIONS = [
        'customers.view',
        'customers.suspend',
        'vendors.view',
        'vendors.approve',
        'vendors.suspend',
        'products.approve',
        'identity.review',
        'wallet.view',
        'wallet.reconcile',
        'plans.view',
        'orders.manage',
        'delivery.update',
        'commissions.manage',
        'vendor_payouts.approve',
        'support.manage',
        'finance.reconcile',
        'affiliates.manage',
        'affiliate_conversions.review',
        'affiliate_payouts.approve',
        'vendor_fees.manage',
        'ai_settings.manage',
        'reports.view',
        'settings.manage',
        'roles.manage',
    ];

    private const ROLE_PERMISSIONS = [
        'Super Administrator' => [], // Gate::before grants every ability.
        'Administrator' => [
            'customers.view',
            'customers.suspend',
            'vendors.view',
            'vendors.approve',
            'vendors.suspend',
            'products.approve',
            'identity.review',
            'wallet.view',
            'plans.view',
            'orders.manage',
            'delivery.update',
            'commissions.manage',
            'support.manage',
            'vendor_fees.manage',
            'ai_settings.manage',
            'reports.view',
            'affiliates.manage',
            'affiliate_conversions.review',
        ],
        'Support Agent' => [
            'customers.view',
            'support.manage',
        ],
        'Logistics Personnel' => [
            'delivery.update',
        ],
        'Finance Officer' => [
            'wallet.view',
            'wallet.reconcile',
            'finance.reconcile',
            'affiliate_payouts.approve',
            'vendor_payouts.approve',
            'reports.view',
        ],
        'Vendor' => [],
        'Customer' => [],
    ];

    public function run(): void
    {
        Cache::forget(config('permission.cache.key'));

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($permissions);
        }
    }
}
