<?php

use App\Models\Setting;
use App\Models\User;
use App\Modules\Payments\Models\PaystackTransaction;
use App\Modules\Risk\Commands\SweepRiskFlags;
use App\Modules\Risk\Models\RiskFlag;
use App\Modules\Risk\Services\RiskFlagService;
use App\Shared\Enums\PaystackTransactionStatus;
use App\Shared\Enums\UserStatus;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Phase 2D risk flags.
 *
 * The rule the plan states outright — and the first thing tested here — is
 * that a flag never suspends anybody. It raises a row for a human to look at,
 * and that is the whole of its authority. Locking a customer out of money they
 * have saved because a heuristic fired is a worse outcome than the fraud it
 * was guarding against.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->risk = app(RiskFlagService::class);
    $this->customer = User::factory()->create();
});

function failedCharges(User $user, int $count, ?string $at = null): void
{
    for ($i = 0; $i < $count; $i++) {
        $transaction = PaystackTransaction::query()->create([
            'user_id' => $user->id,
            'purpose' => 'order',
            'paystack_reference' => 'FMX_'.$user->id.'_'.$i.'_'.uniqid(),
            'amount_kobo' => 100_000,
            'currency' => 'NGN',
            'status' => PaystackTransactionStatus::Failed,
        ]);

        // Backdated after the fact: `created_at` is not fillable, so passing
        // it to create() is silently dropped and every row lands on today —
        // which would make the out-of-window test pass for the wrong reason.
        if ($at !== null) {
            $transaction->forceFill(['created_at' => $at])->save();
        }
    }
}

it('never suspends or touches the account it flags', function () {
    failedCharges($this->customer, 5);

    $this->risk->sweep();

    $this->customer->refresh();

    // The entire point: a flag is a note for a human, not an action.
    expect(RiskFlag::query()->count())->toBe(1)
        ->and($this->customer->status)->toBe(UserStatus::Active)
        ->and($this->customer->banned_at ?? null)->toBeNull();
});

it('flags repeated failed payments inside the window', function () {
    failedCharges($this->customer, 4);

    expect($this->risk->sweep())->toBeGreaterThan(0);

    $flag = RiskFlag::query()->firstOrFail();

    expect($flag->rule)->toBe(RiskFlagService::RULE_FAILED_PAYMENTS)
        ->and($flag->status)->toBe(RiskFlag::STATUS_OPEN)
        // The evidence travels with the flag so a reviewer sees the numbers.
        ->and($flag->evidence['failures'])->toBe(4);
});

it('ignores failures that fall outside the window', function () {
    failedCharges($this->customer, 5, now()->subDays(60)->toDateTimeString());

    $this->risk->sweep();

    expect(RiskFlag::query()->count())->toBe(0);
});

it('respects a threshold staff have changed', function () {
    failedCharges($this->customer, 4);

    // Raise the bar above what this customer did.
    Setting::set('risk.failed_payments_threshold', 10, 'risk');

    expect($this->risk->sweep())->toBe(0)
        ->and(RiskFlag::query()->count())->toBe(0);
});

it('does not raise a second flag while the first is still unreviewed', function () {
    failedCharges($this->customer, 5);

    $this->risk->sweep();
    $this->risk->sweep();
    $this->risk->sweep();

    // Otherwise a daily sweep buries the reviewer in copies of one condition.
    expect(RiskFlag::query()->count())->toBe(1);
});

it('records what a reviewer decided without acting on it', function () {
    failedCharges($this->customer, 5);
    $this->risk->sweep();

    $staff = User::factory()->create();
    $flag = RiskFlag::query()->firstOrFail();

    $this->risk->review($staff, $flag, RiskFlag::OUTCOME_NO_ACTION, 'Card expired, customer updated it.');

    $flag->refresh();

    expect($flag->status)->toBe(RiskFlag::STATUS_REVIEWED)
        ->and($flag->outcome)->toBe(RiskFlag::OUTCOME_NO_ACTION)
        ->and($flag->reviewed_by)->toBe($staff->id)
        // Even "actioned" is only ever a note that a human went and did
        // something — the flag itself still changes nothing.
        ->and($this->customer->refresh()->status)->toBe(UserStatus::Active);
});

it('can raise the same rule again once the earlier flag has been reviewed', function () {
    failedCharges($this->customer, 5);
    $this->risk->sweep();

    $staff = User::factory()->create();
    $this->risk->review($staff, RiskFlag::query()->firstOrFail(), RiskFlag::OUTCOME_WATCHING);

    $this->risk->sweep();

    expect(RiskFlag::query()->count())->toBe(2);
});

it('runs from the scheduler command', function () {
    failedCharges($this->customer, 5);

    $this->artisan(SweepRiskFlags::class)->assertSuccessful();

    expect(RiskFlag::query()->count())->toBe(1);
});
