<?php

namespace App\Shared;

use Laravel\Pennant\Feature;

/**
 * Central registry of feature flags gating Phase 2/3 modules so they can
 * ship dark and be enabled per environment/cohort without a deploy
 * (docs/firstmarket_Implementation_Plan.md section 1.2). Registered from
 * App\Providers\AppServiceProvider::boot().
 */
final class Features
{
    public const WISHLIST = 'wishlist';

    public const REWARDS = 'rewards';

    public const REFERRALS = 'referrals';

    public const AFFILIATES = 'affiliates';

    public const AUTOMATIC_DEBIT = 'automatic-debit';

    public const AGENT_NETWORK = 'agent-network';

    public const COOPERATIVE_SAVINGS = 'cooperative-savings';

    public static function register(): void
    {
        foreach (self::all() as $feature) {
            Feature::define($feature, fn () => false);
        }
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::WISHLIST,
            self::REWARDS,
            self::REFERRALS,
            self::AFFILIATES,
            self::AUTOMATIC_DEBIT,
            self::AGENT_NETWORK,
            self::COOPERATIVE_SAVINGS,
        ];
    }
}
