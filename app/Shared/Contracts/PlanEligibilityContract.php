<?php

namespace App\Shared\Contracts;

use App\Models\User;

/**
 * Swappable-implementation contract (same pattern as SmsSenderContract /
 * PaymentGatewayContract) gating whether a customer may bundle multiple
 * products into one multi-product Product Target Plan (Sprint 8). Only the
 * bundling feature is gated — single-product plans never call this.
 * Sprint 9 swaps RuleBasedPlanEligibilityChecker for an AI-scored
 * implementation behind this same contract.
 */
interface PlanEligibilityContract
{
    /**
     * Null when $user may start a bundled plan; otherwise a customer-facing
     * reason they may not, right now.
     */
    public function reasonIneligible(User $user): ?string;
}
