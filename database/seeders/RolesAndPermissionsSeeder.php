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
        'catalog.manage',
        'identity.review',
        'savings.view',
        'savings.reconcile',
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
        // Creating a staff account is creating a way into the admin domain,
        // so it is its own permission rather than folded into roles.manage.
        'staff.manage',
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
            'catalog.manage',
            'identity.review',
            'savings.view',
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
            'staff.manage',
            // The wording of the terms, privacy policy and data-deletion
            // pages. An Administrator can already suspend a vendor and change
            // what customers are charged; withholding the ability to correct
            // a policy would only mean waiting on the one Super Administrator
            // account to fix a sentence.
            'settings.manage',
        ],
        'Support Agent' => [
            'customers.view',
            'support.manage',
        ],
        'Logistics Personnel' => [
            'delivery.update',
        ],
        'Finance Officer' => [
            'savings.view',
            'savings.reconcile',
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
