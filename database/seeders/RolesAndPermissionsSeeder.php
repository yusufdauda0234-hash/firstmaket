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
        // Working the returns queue and paying money back are different
        // levels of trust, so they are different permissions.
        'returns.manage',
        'refunds.issue',
        'risk.review',
        'affiliate_payouts.approve',
        'vendor_fees.manage',
        'ai_settings.manage',
        'reports.view',
        'settings.manage',
        'roles.manage',
        // Creating a staff account is creating a way into the admin domain,
        // so it is its own permission rather than folded into roles.manage.
        'staff.manage',
        // Downloads a full SQL dump and can wipe any table's data outright.
        // Like roles.manage, seeded but granted to no role by default — only
        // Super Administrator (via Gate::before) reaches it out of the box.
        'system.backup',
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
            'returns.manage',
            'risk.review',
        ],
        'Support Agent' => [
            'customers.view',
            'support.manage',
            // Can work the returns queue — approve, reject, chase — but not
            // pay money out. That stays with finance.
            'returns.manage',
            /*
             * An agent answering "where is my order" has always been able to
             * see the customer's savings context on the lookup screen. That
             * screen now checks these two rather than showing the figures to
             * anyone who can open it, so they are granted here to keep the
             * job exactly as it was — the difference is that an admin can now
             * take them away and build a support role without financial
             * visibility, which was not possible before.
             */
            'savings.view',
            'plans.view',
        ],
        'Logistics Personnel' => [
            'delivery.update',
        ],
        'Finance Officer' => [
            'savings.view',
            // The one role besides an administrator that may send money back.
            'refunds.issue',
            'returns.manage',
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

            // These seven names are exactly the ones the codebase depends on
            // existing — Gate::before checks 'Super Administrator' by name,
            // StaffController assumes 'Vendor'/'Customer' exist — so they are
            // marked system on every seed, not just the migration that added
            // the column. A role editor built later must never be able to
            // rename or delete one of these.
            if ($role->is_system !== true) {
                $role->forceFill(['is_system' => true])->save();
            }
        }
    }
}
