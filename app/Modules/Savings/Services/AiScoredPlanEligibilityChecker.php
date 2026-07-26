<?php

namespace App\Modules\Savings\Services;

use App\Models\User;
use App\Shared\Contracts\PlanEligibilityContract;

/**
 * Sprint 9 swap-in for RuleBasedPlanEligibilityChecker, bound to
 * PlanEligibilityContract in AppServiceProvider (docs/FirstMaket_Implementation_Plan.md
 * Sprint 9: "swapping the Sprint 8 rule-based multi-product plan eligibility
 * checker for an AI-scored one"). The rule-based checker is kept as the
 * explicit floor/fallback rather than replaced outright — its human-readable
 * reason is always what the customer sees, so a blocked customer is never
 * facing an unexplainable score. No AI provider is wired in yet (no
 * AI_PROVIDER_KEY configured): until one is, this behaves identically to the
 * rule-based checker alone. A future scored implementation should compute an
 * advisory score from contribution reliability/purchase history and use it
 * only to loosen — never tighten — the rule-based floor, then still explain
 * the final decision in plain language via $fallback's reason text.
 */
class AiScoredPlanEligibilityChecker implements PlanEligibilityContract
{
    public function __construct(private readonly RuleBasedPlanEligibilityChecker $fallback) {}

    public function reasonIneligible(User $user): ?string
    {
        return $this->fallback->reasonIneligible($user);
    }
}
