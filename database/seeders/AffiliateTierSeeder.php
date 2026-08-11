<?php

namespace Database\Seeders;

use App\Modules\Affiliates\Models\AffiliateTier;
use Illuminate\Database\Seeder;

/**
 * Starting commission ladder.
 *
 * Seeded rather than left to an admin screen for the same reason product
 * fields are: without at least one tier the resolver falls back to a single
 * flat percentage, and a partner programme with no visible ladder gives
 * nobody a reason to push past their first sale. The numbers are deliberately
 * round and editable — they are a starting point, not a policy.
 *
 * Never overwrites a tier staff have edited.
 */
class AffiliateTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'name' => 'Starter',
                'description' => 'Where every partner begins.',
                'commission_type' => AffiliateTier::TYPE_PERCENT,
                'commission_percent' => 5,
                'vendor_recruitment_kobo' => 200_000,   // ₦2,000 per recruited seller
                'min_delivered_conversions' => 0,
                'min_delivered_value_kobo' => 0,
                'is_default' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Growth',
                'description' => '10 delivered orders, or ₦500,000 referred.',
                'commission_type' => AffiliateTier::TYPE_PERCENT,
                'commission_percent' => 7,
                'vendor_recruitment_kobo' => 350_000,
                'min_delivered_conversions' => 10,
                'min_delivered_value_kobo' => 500_000_00,
                'is_default' => false,
                'sort_order' => 2,
            ],
            [
                'name' => 'Partner',
                'description' => '50 delivered orders, or ₦2,500,000 referred.',
                'commission_type' => AffiliateTier::TYPE_PERCENT,
                'commission_percent' => 10,
                'vendor_recruitment_kobo' => 500_000,
                'min_delivered_conversions' => 50,
                'min_delivered_value_kobo' => 2_500_000_00,
                'is_default' => false,
                'sort_order' => 3,
            ],
        ];

        foreach ($tiers as $tier) {
            AffiliateTier::query()->firstOrCreate(['name' => $tier['name']], $tier);
        }
    }
}
