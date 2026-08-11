<?php

namespace App\Shared\Support;

/**
 * Every staff permission, grouped for a human to read.
 *
 * This exists because `roles.manage` used to mean "read forty raw
 * dot-separated keys off a list and guess". The groups below mirror
 * AdminLayout's own sidebar sections — Vendors, Orders, Logistics, Finance,
 * and so on — so a role editor built from this catalog produces the same
 * mental model as the sidebar the role will actually get.
 *
 * A permission not listed here still works everywhere it always did; it just
 * lands in the "Other" bucket in the UI rather than a named group, which is a
 * deliberate safety net — a key added to the seeder and forgotten here is
 * still visible and grantable, just not tidily labelled.
 */
class PermissionCatalog
{
    /**
     * group label => [permission key => human label].
     *
     * @return array<string, array<string, string>>
     */
    public static function groups(): array
    {
        return [
            'Catalogue' => [
                'products.approve' => 'Approve or reject product listings',
                'catalog.manage' => 'Manage categories and product fields',
            ],
            'Vendors' => [
                'vendors.view' => 'View vendor profiles',
                'vendors.approve' => 'Approve or reject vendor applications',
                'vendors.suspend' => 'Suspend or reinstate a vendor',
                'commissions.manage' => 'Set commission rules',
                'vendor_fees.manage' => 'Set listing fees and delivery rates',
                'vendor_payouts.approve' => 'Approve vendor payout batches',
            ],
            'Orders & Logistics' => [
                'orders.manage' => 'Manage orders and dispatch',
                'delivery.update' => 'Update delivery status as a courier',
            ],
            'Customers & Identity' => [
                'customers.view' => 'View customer accounts',
                'customers.suspend' => 'Suspend or reinstate a customer',
                'identity.review' => 'Review phone/identity verification',
            ],
            'Support & Returns' => [
                'support.manage' => 'Work the support ticket queue',
                'returns.manage' => 'Review and approve return requests',
            ],
            'Risk' => [
                'risk.review' => 'Review and close risk flags',
            ],
            'Finance' => [
                'savings.view' => 'See a customer\'s savings balance',
                'plans.view' => 'See what a customer is saving towards',
                'savings.reconcile' => 'Reconcile savings/plan payments',
                'finance.reconcile' => 'Reconcile settlements',
                'refunds.issue' => 'Send money back to a customer',
                'affiliate_payouts.approve' => 'Approve affiliate payout batches',
            ],
            'Growth' => [
                'affiliates.manage' => 'Approve or reject affiliate applications',
                'affiliate_conversions.review' => 'Review affiliate conversions',
            ],
            'Reports' => [
                'reports.view' => 'View operational reports',
            ],
            'Staff & Roles' => [
                'staff.manage' => 'Create staff accounts',
                'roles.manage' => 'Create and edit staff roles',
            ],
            'System Settings' => [
                'ai_settings.manage' => 'Configure AI-assisted listing review',
                'settings.manage' => 'Edit operations, growth and legal-page settings',
                // Deliberately unassigned in the seeder — see
                // RolesAndPermissionsSeeder::PERMISSIONS. Nobody holds this
                // out of the box, same as roles.manage.
                'system.backup' => 'Download database backups and delete table data',
            ],
        ];
    }

    /** Every permission key the catalog knows how to label, flat. */
    public static function all(): array
    {
        $flat = [];

        foreach (self::groups() as $permissions) {
            $flat += $permissions;
        }

        return $flat;
    }

    public static function labelFor(string $permission): string
    {
        return self::all()[$permission] ?? $permission;
    }

    /**
     * Permission keys held by every account of this role, not because they
     * were assigned but because they are seeded system roles the codebase
     * checks by name.
     *
     * Only 'Super Administrator' actually matters here — Gate::before grants
     * it every ability regardless of what is in the pivot table — but naming
     * it explicitly means the role editor can show the truth instead of an
     * empty permission list that reads as "this role can do nothing".
     */
    public const SUPER_ADMINISTRATOR = 'Super Administrator';

    /**
     * Names no role editor may ever create, rename to, or delete — either
     * because Gate::before checks the name directly, or because other code
     * assumes the role exists.
     *
     * @return list<string>
     */
    public static function reservedRoleNames(): array
    {
        return [
            self::SUPER_ADMINISTRATOR,
            'Administrator',
            'Support Agent',
            'Logistics Personnel',
            'Finance Officer',
            'Vendor',
            'Customer',
        ];
    }

    /**
     * Names that never appear in the "assign a staff member this role" list,
     * distinct from {@see reservedRoleNames()} — those four other system
     * roles (Administrator, Support Agent, Logistics Personnel, Finance
     * Officer) are themselves ordinary staff jobs and stay assignable; they
     * just cannot be renamed or deleted.
     *
     * - Super Administrator bypasses every permission check, so handing it
     *   out is a deployment-time decision, never a click in this screen.
     * - Vendor and Customer are account types, not staff jobs.
     *
     * @return list<string>
     */
    public static function excludedFromStaffAssignment(): array
    {
        return [self::SUPER_ADMINISTRATOR, 'Vendor', 'Customer'];
    }
}
