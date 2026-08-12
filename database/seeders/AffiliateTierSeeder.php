<?php

namespace Database\Seeders;

use App\Modules\Affiliates\Models\AffiliateRankRequirement;
use App\Modules\Affiliates\Models\AffiliateTier;
use Illuminate\Database\Seeder;

/**
 * The starting rank ladder.
 *
 * Three ranks, each widening what a partner may do: more referrals before
 * they must come back, longer-lived links, more of them at once, and a better
 * rate. The first is entered automatically on approval; the two above it ask
 * for documents somebody has to look at.
 *
 * The numbers are deliberately round and every one of them is editable from
 * Admin → Growth → Affiliate ranks. They are a starting point, not a policy.
 *
 * Never overwrites a rank staff have edited.
 */
class AffiliateTierSeeder extends Seeder
{
    public function run(): void
    {
        $ladder = [
            [
                'tier' => [
                    'name' => 'Starter',
                    'description' => 'Where every partner begins. Share with a few people and see how it goes.',
                    'commission_type' => AffiliateTier::TYPE_PERCENT,
                    'commission_percent' => 5,
                    'vendor_recruitment_kobo' => 200_000,
                    'referral_quota' => 3,
                    'link_expiry_days' => 30,
                    'max_active_links' => 1,
                    // Entered on approval — the application itself was the check.
                    'requires_approval' => false,
                    'min_delivered_conversions' => 0,
                    'min_delivered_value_kobo' => 0,
                    'is_default' => true,
                    'sort_order' => 1,
                ],
                'requirements' => [],
            ],
            [
                'tier' => [
                    'name' => 'Growth',
                    'description' => 'For partners who have proved the first three. Longer links, more of them.',
                    'commission_type' => AffiliateTier::TYPE_PERCENT,
                    'commission_percent' => 7,
                    'vendor_recruitment_kobo' => 350_000,
                    'referral_quota' => 25,
                    'link_expiry_days' => 90,
                    'max_active_links' => 5,
                    'requires_approval' => true,
                    'min_delivered_conversions' => 3,
                    'min_delivered_value_kobo' => 0,
                    'is_default' => false,
                    'sort_order' => 2,
                ],
                'requirements' => [
                    [
                        'label' => 'CAC registration document',
                        'help_text' => 'Your business registration certificate, as a PDF or photo.',
                        'type' => AffiliateRankRequirement::TYPE_DOCUMENT,
                        'is_required' => true,
                        'sort_order' => 1,
                    ],
                    [
                        'label' => 'Business or brand name',
                        'help_text' => 'The name your audience knows you by.',
                        'type' => AffiliateRankRequirement::TYPE_TEXT,
                        'is_required' => true,
                        'sort_order' => 2,
                    ],
                ],
            ],
            [
                'tier' => [
                    'name' => 'Partner',
                    'description' => 'No referral limit and links that do not expire.',
                    'commission_type' => AffiliateTier::TYPE_PERCENT,
                    'commission_percent' => 10,
                    'vendor_recruitment_kobo' => 500_000,
                    // Unlimited: a ladder that never ends is not a ladder.
                    'referral_quota' => 0,
                    'link_expiry_days' => 0,
                    'max_active_links' => 0,
                    'requires_approval' => true,
                    'min_delivered_conversions' => 25,
                    'min_delivered_value_kobo' => 2_500_000_00,
                    'is_default' => false,
                    'sort_order' => 3,
                ],
                'requirements' => [
                    [
                        'label' => 'CAC registration document',
                        'help_text' => 'Re-confirm your business registration.',
                        'type' => AffiliateRankRequirement::TYPE_DOCUMENT,
                        'is_required' => true,
                        'sort_order' => 1,
                    ],
                    [
                        'label' => 'Means of identification',
                        'help_text' => 'NIN slip, driver\'s licence, or international passport.',
                        'type' => AffiliateRankRequirement::TYPE_DOCUMENT,
                        'is_required' => true,
                        'sort_order' => 2,
                    ],
                    [
                        'label' => 'How do you reach your audience?',
                        'help_text' => 'A sentence or two — the platform, and roughly how many people.',
                        'type' => AffiliateRankRequirement::TYPE_TEXT,
                        'is_required' => true,
                        'sort_order' => 3,
                    ],
                ],
            ],
        ];

        foreach ($ladder as $rung) {
            $tier = AffiliateTier::query()->firstOrCreate(
                ['name' => $rung['tier']['name']],
                $rung['tier'],
            );

            foreach ($rung['requirements'] as $requirement) {
                AffiliateRankRequirement::query()->firstOrCreate(
                    ['tier_id' => $tier->id, 'label' => $requirement['label']],
                    $requirement + ['tier_id' => $tier->id],
                );
            }
        }
    }
}
