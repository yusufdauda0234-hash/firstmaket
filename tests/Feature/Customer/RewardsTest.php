<?php

use App\Models\User;
use App\Modules\Rewards\Models\UserReward;
use App\Modules\Savings\Events\PlanCompleted;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Enums\SavingsGoalStatus;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the bronze badge before a plan is completed', function () {
    $customer = User::factory()->create();

    $this->actingAs($customer)
        ->get('/account/rewards')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Account/Rewards')
            ->where('current.name', 'Bronze')
            ->where('lifetimeCompletedSavingsKobo', 0)
            ->where('nextTier.name', 'Silver'));

    expect(UserReward::query()->where('user_id', $customer->id)->exists())->toBeFalse();
});

it('recalculates the badge from fulfilled plans only', function () {
    $customer = User::factory()->create();

    SavingsGoal::query()->create([
        'user_id' => $customer->id,
        'target_kobo' => 1_500_000,
        'status' => SavingsGoalStatus::Fulfilled,
    ]);
    SavingsGoal::query()->create([
        'user_id' => $customer->id,
        'target_kobo' => 9_000_000,
        'status' => SavingsGoalStatus::Saving,
    ]);

    PlanCompleted::dispatch(1, $customer->id, 1_500_000);

    expect(UserReward::query()->where('user_id', $customer->id)->first())
        ->lifetime_completed_savings->toBe(1_500_000)
        ->tier->name->toBe('Silver');
});

it('recalculates idempotently when the completion event is replayed', function () {
    $customer = User::factory()->create();
    SavingsGoal::query()->create([
        'user_id' => $customer->id,
        'target_kobo' => 6_000_000,
        'status' => SavingsGoalStatus::Fulfilled,
    ]);

    PlanCompleted::dispatch(1, $customer->id, 6_000_000);
    PlanCompleted::dispatch(1, $customer->id, 6_000_000);

    expect(UserReward::query()->where('user_id', $customer->id)->count())->toBe(1)
        ->and(UserReward::query()->where('user_id', $customer->id)->first()->tier->name)->toBe('Gold');
});
