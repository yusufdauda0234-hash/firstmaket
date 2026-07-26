<?php

use App\Models\User;
use App\Modules\Savings\Services\AiScoredPlanEligibilityChecker;
use App\Modules\Savings\Services\RuleBasedPlanEligibilityChecker;
use App\Shared\Contracts\PlanEligibilityContract;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Sprint 9 QA: PlanEligibilityContract now resolves to the AI-scored
 * checker, which is required to keep the rule-based checker as its explicit
 * fallback — a customer's reason is never an unexplainable score (see
 * AiScoredPlanEligibilityChecker's docblock for why no real scorer is wired
 * in yet).
 */
beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('resolves the contract to the AI-scored checker', function () {
    expect(app(PlanEligibilityContract::class))->toBeInstanceOf(AiScoredPlanEligibilityChecker::class);
});

it('matches the rule-based checker\'s reason exactly, since no scorer overrides it yet', function () {
    $user = User::factory()->create();

    $aiReason = app(AiScoredPlanEligibilityChecker::class)->reasonIneligible($user);
    $ruleReason = app(RuleBasedPlanEligibilityChecker::class)->reasonIneligible($user);

    expect($aiReason)->toBe($ruleReason)
        ->and($aiReason)->not->toBeNull();
});
