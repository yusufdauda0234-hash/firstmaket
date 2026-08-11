<?php

use App\Models\Setting;
use App\Models\User;
use App\Modules\Savings\Models\PlanPayment;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Services\SavingsAssistant;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\SavingsGoalStatus;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Phase 2C savings assistant.
 *
 * Rules over a customer's own payment history — no model, no third party, and
 * nothing leaves the platform. The requirement the plan states plainly is that
 * the assistant stays advisory: it must never move money or change a plan, and
 * the first test here holds that line.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->customer = User::factory()->create();
    $this->assistant = app(SavingsAssistant::class);
});

function assistantPlan(User $owner, array $overrides = []): SavingsGoal
{
    return SavingsGoal::query()->create(array_merge([
        'user_id' => $owner->id,
        'target_kobo' => 500_000,
        'delivery_fee_kobo' => 0,
        'cadence' => PlanCadence::Monthly,
        'installments' => 5,
        'payments_made' => 2,
        'installment_kobo' => 100_000,
        'paid_kobo' => 200_000,
        'status' => SavingsGoalStatus::Saving,
        'next_due_at' => now()->addMonth(),
        'missed_payments_allowed' => 2,
    ], $overrides));
}

function assistantPayments(User $owner, SavingsGoal $plan, array $amounts): void
{
    foreach ($amounts as $amount) {
        PlanPayment::query()->create([
            'savings_goal_id' => $plan->id,
            'user_id' => $owner->id,
            'amount_kobo' => $amount,
            'paid_before_kobo' => 0,
            'paid_after_kobo' => $amount,
            'source' => 'card',
            'reference' => 'TEST-'.uniqid(),
        ]);
    }
}

it('gives advice without touching the plan or the money', function () {
    $plan = assistantPlan($this->customer);
    assistantPayments($this->customer, $plan, [100_000, 100_000, 100_000]);

    $before = [$plan->paid_kobo, $plan->target_kobo, $plan->status];

    $this->assistant->adviceFor($this->customer);

    $plan->refresh();

    // Advisory means advisory: reading advice changes nothing.
    expect([$plan->paid_kobo, $plan->target_kobo, $plan->status])->toBe($before);
});

it('says it does not know yet when there is barely any history', function () {
    assistantPlan($this->customer);

    $notes = $this->assistant->adviceFor($this->customer);

    // Better than inventing a pattern from one payment.
    expect($notes)->toHaveCount(1)
        ->and($notes[0]['key'])->toBe('getting_started');
});

it('works out what the customer usually pays', function () {
    $plan = assistantPlan($this->customer);
    assistantPayments($this->customer, $plan, [100_000, 200_000, 300_000]);

    $notes = collect($this->assistant->adviceFor($this->customer));
    $typical = $notes->firstWhere('key', 'typical_payment');

    expect($typical)->not->toBeNull()
        ->and($typical['evidence']['typical_kobo'])->toBe(200_000);
});

it('points out a plan that has fallen behind, and what would fix it', function () {
    $plan = assistantPlan($this->customer, ['next_due_at' => now()->subMonths(2)]);
    assistantPayments($this->customer, $plan, [100_000, 100_000, 100_000]);

    $notes = collect($this->assistant->adviceFor($this->customer));
    $behind = $notes->first(fn ($note) => str_starts_with($note['key'], 'behind:'));

    expect($behind)->not->toBeNull()
        ->and($behind['tone'])->toBe('warning')
        ->and($behind['evidence']['behind_kobo'])->toBeGreaterThan(0);
});

it('never suggests paying more while a plan is behind', function () {
    $plan = assistantPlan($this->customer, ['next_due_at' => now()->subMonths(3)]);
    // Habit far exceeds the instalment, but they are behind.
    assistantPayments($this->customer, $plan, [400_000, 400_000, 400_000]);

    $notes = collect($this->assistant->adviceFor($this->customer));

    // Telling somebody who is behind that they could go faster reads as
    // tone deaf, so the rule refuses to fire.
    expect($notes->firstWhere('key', 'finish_sooner'))->toBeNull();
});

it('offers to finish sooner when the habit genuinely beats the schedule', function () {
    $plan = assistantPlan($this->customer, ['next_due_at' => now()->addMonth()]);
    assistantPayments($this->customer, $plan, [300_000, 300_000, 300_000]);

    $notes = collect($this->assistant->adviceFor($this->customer));

    expect($notes->firstWhere('key', 'finish_sooner'))->not->toBeNull();
});

it('congratulates a plan that is nearly done', function () {
    $plan = assistantPlan($this->customer, ['paid_kobo' => 400_000]);
    assistantPayments($this->customer, $plan, [100_000, 100_000, 100_000]);

    $notes = collect($this->assistant->adviceFor($this->customer));

    expect($notes->first(fn ($note) => str_starts_with($note['key'], 'nearly_there:')))->not->toBeNull();
});

it('lets staff change what counts as behind', function () {
    $plan = assistantPlan($this->customer, ['next_due_at' => now()->subMonths(2)]);
    assistantPayments($this->customer, $plan, [100_000, 100_000, 100_000]);

    // Be far more forgiving than one whole instalment.
    Setting::set('assistant.behind_tolerance_percent', 900, 'assistant');

    $notes = collect($this->assistant->adviceFor($this->customer));

    expect($notes->first(fn ($note) => str_starts_with($note['key'], 'behind:')))->toBeNull();
});

it('keeps one customer advice away from another', function () {
    $plan = assistantPlan($this->customer);
    assistantPayments($this->customer, $plan, [100_000, 100_000, 100_000]);

    $stranger = User::factory()->create();

    expect($this->assistant->adviceFor($stranger)[0]['key'])->toBe('getting_started');
});
