<?php

namespace Database\Seeders;

use App\Modules\Vendor\Models\VendorRatingTier;
use Illuminate\Database\Seeder;

/**
 * The four vendor bands Phase 2D names, with sensible opening thresholds.
 *
 * Seeded rather than hardcoded so staff can rename, retune or retire any of
 * them from the admin screen. `updateOrCreate` on the name keeps a re-seed
 * from duplicating tiers or trampling thresholds an admin has already tuned —
 * only a genuinely new tier is inserted.
 */
class VendorRatingTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'name' => 'Bronze',
                'colour' => 'amber',
                'minimum_score' => 0,
                'minimum_delivered_orders' => 0,
                'maximum_rejection_percent' => null,
                'maximum_return_percent' => null,
                'benefits' => ['Listed in search and category pages'],
                'sort_order' => 1,
            ],
            [
                'name' => 'Silver',
                'colour' => 'slate',
                'minimum_score' => 60,
                'minimum_delivered_orders' => 10,
                'maximum_rejection_percent' => 20,
                'maximum_return_percent' => 20,
                'benefits' => ['Silver badge on your storefront', 'Priority in support queues'],
                'sort_order' => 2,
            ],
            [
                'name' => 'Gold',
                'colour' => 'yellow',
                'minimum_score' => 75,
                'minimum_delivered_orders' => 50,
                'maximum_rejection_percent' => 10,
                'maximum_return_percent' => 10,
                'benefits' => ['Gold badge', 'Eligible for homepage placement', 'Faster payout review'],
                'sort_order' => 3,
            ],
            [
                'name' => 'Platinum',
                'colour' => 'brand',
                'minimum_score' => 88,
                'minimum_delivered_orders' => 200,
                'maximum_rejection_percent' => 5,
                'maximum_return_percent' => 5,
                'benefits' => [
                    'Platinum badge',
                    'Featured placement on the home page',
                    'Priority payout processing',
                    'Named account contact',
                ],
                'sort_order' => 4,
            ],
        ];

        foreach ($tiers as $tier) {
            VendorRatingTier::query()->updateOrCreate(
                ['name' => $tier['name']],
                [...$tier, 'status' => true],
            );
        }
    }
}
