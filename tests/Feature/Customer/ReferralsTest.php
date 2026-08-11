<?php

use App\Models\Setting;
use App\Models\User;
use App\Modules\Referrals\Models\Referral;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Savings\Events\PlanCompleted;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Enums\SavingsGoalStatus;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

it('creates a protected invite code and shows the referral page', function () {
    $referrer = User::factory()->create();

    $this->actingAs($referrer)
        ->get('/account/referrals')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Account/Referrals')
            ->where('code', fn ($code) => is_string($code) && strlen($code) === 12)
            ->where('referrals', []));

    $referral = Referral::query()->where('referrer_id', $referrer->id)->firstOrFail();
    expect($referral->referred_id)->toBeNull()
        ->and($referral->referral_code)->toMatch('/^[A-Z0-9]{12}$/')
        ->and($referral->referral_code)->not->toBe((string) $referrer->id);
});

it('snapshots the admin-configured reward on new referral codes', function () {
    Setting::set('referrals.reward_amount_kobo', 75_000, 'growth');
    $user = User::factory()->create();

    $referral = app(ReferralService::class)->codeFor($user);

    expect($referral->reward_amount)->toBe(75_000);
});

it('claims an invite for one new customer and blocks self referral', function () {
    $referrer = User::factory()->create();
    $referred = User::factory()->create();
    $referral = app(ReferralService::class)->codeFor($referrer);

    app(ReferralService::class)->claim($referral->referral_code, $referred);

    expect($referral->refresh()->referred_id)->toBe($referred->id);

    expect(fn () => app(ReferralService::class)->claim($referral->referral_code, $referrer))
        ->not->toThrow(ValidationException::class);
});

it('blocks self referral before a code is claimed', function () {
    $referrer = User::factory()->create();
    $referral = app(ReferralService::class)->codeFor($referrer);

    expect(fn () => app(ReferralService::class)->claim($referral->referral_code, $referrer))
        ->toThrow(ValidationException::class);

    expect($referral->refresh()->referred_id)->toBeNull();
});

it('qualifies a referral only on the referred customer first completed plan', function () {
    $referrer = User::factory()->create();
    $referred = User::factory()->create();
    $referral = app(ReferralService::class)->codeFor($referrer);
    app(ReferralService::class)->claim($referral->referral_code, $referred);

    $plan = SavingsGoal::query()->create([
        'user_id' => $referred->id,
        'target_kobo' => 2_000_000,
        'status' => SavingsGoalStatus::Fulfilled,
    ]);

    PlanCompleted::dispatch($plan->id, $referred->id, 2_000_000);
    $earned = $referral->refresh();

    expect($earned->status)->toBe('earned')
        ->and($earned->qualified_plan_id)->toBe($plan->id)
        ->and($earned->reward_credited_at)->not->toBeNull();

    PlanCompleted::dispatch($plan->id + 1, $referred->id, 3_000_000);

    expect($referral->refresh()->qualified_plan_id)->toBe($plan->id)
        ->and(Referral::query()->where('referred_id', $referred->id)->where('status', 'earned')->count())->toBe(1);
});
