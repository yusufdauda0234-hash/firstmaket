<?php

use App\Models\User;
use App\Modules\Payments\Commands\ChargeDueAutomaticDebits;
use App\Modules\Payments\Models\AutomaticDebit;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\PaystackTransaction;
use App\Modules\Payments\Services\AutomaticDebitService;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Services\SavingsGoalService;
use App\Shared\Contracts\PaymentGatewayContract;
use App\Shared\Enums\AutomaticDebitStatus;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\SavingsGoalStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakePaymentGateway;

/**
 * Phase 2B: scheduled automatic debit.
 *
 * The rule the whole feature rests on: this charges the card and stops. The
 * plan is credited by the signature-verified webhook, exactly as it is for a
 * payment made by hand — so there is only ever one path into `paid_kobo`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGatewayContract::class, $this->gateway);

    $this->customer = User::factory()->create();
    $this->debits = app(AutomaticDebitService::class);
});

function savedCard(User $owner): PaymentAuthorization
{
    return PaymentAuthorization::query()->create([
        'user_id' => $owner->id,
        'authorization_code' => 'AUTH_'.fake()->bothify('??####'),
        'last4' => '4081',
        'card_type' => 'visa',
        'reusable' => true,
        'active' => true,
    ]);
}

function debitablePlan(User $owner, array $overrides = []): SavingsGoal
{
    return SavingsGoal::query()->create(array_merge([
        'user_id' => $owner->id,
        'target_kobo' => 500_000,
        'delivery_fee_kobo' => 0,
        'cadence' => PlanCadence::Monthly,
        'installments' => 5,
        'payments_made' => 1,
        'installment_kobo' => 100_000,
        'paid_kobo' => 100_000,
        'status' => SavingsGoalStatus::Saving,
        'next_due_at' => now()->subDay(),
        'started_at' => now()->subMonth(),
        'missed_payments_allowed' => 2,
    ], $overrides));
}

it('refuses to switch on without a card the customer has already used', function () {
    $plan = debitablePlan($this->customer);

    // No reusable authorization exists, because they have never paid by card.
    expect(fn () => $this->debits->enable($this->customer, $plan))
        ->toThrow(ValidationException::class);
});

it('switches on against the saved card and follows the plan schedule', function () {
    savedCard($this->customer);
    $plan = debitablePlan($this->customer);

    $debit = $this->debits->enable($this->customer, $plan);

    expect($debit->status)->toBe(AutomaticDebitStatus::Active)
        ->and($debit->amount_kobo)->toBe(100_000)
        ->and($debit->next_run_at->timestamp)->toBe($plan->next_due_at->timestamp);
});

it('will not let one customer set up a debit on another customer plan', function () {
    savedCard($this->customer);
    $plan = debitablePlan($this->customer);
    $stranger = User::factory()->create();
    savedCard($stranger);

    expect(fn () => $this->debits->enable($stranger, $plan))
        ->toThrow(ValidationException::class);
});

it('charges a due debit and records the transaction the webhook will credit', function () {
    savedCard($this->customer);
    $plan = debitablePlan($this->customer);
    $this->debits->enable($this->customer, $plan);

    $this->artisan(ChargeDueAutomaticDebits::class)->assertSuccessful();

    expect($this->gateway->lastAmountKobo())->toBe(100_000);

    $transaction = PaystackTransaction::query()->where('savings_goal_id', $plan->id)->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->purpose)->toBe('plan_installment')
        ->and($transaction->amount_kobo)->toBe(100_000);

    // Crucially: the charge alone must not move the plan's money. Only the
    // verified webhook does that.
    expect($plan->refresh()->paid_kobo)->toBe(100_000);
});

it('does not charge twice when the command runs again straight away', function () {
    savedCard($this->customer);
    $plan = debitablePlan($this->customer);
    $this->debits->enable($this->customer, $plan);

    $this->artisan(ChargeDueAutomaticDebits::class)->assertSuccessful();
    $this->artisan(ChargeDueAutomaticDebits::class)->assertSuccessful();

    expect($this->gateway->charges)->toHaveCount(1);
});

it('retries once a day later when the card is declined', function () {
    savedCard($this->customer);
    $plan = debitablePlan($this->customer);
    $debit = $this->debits->enable($this->customer, $plan);

    $this->gateway->declineCharges = true;
    $this->artisan(ChargeDueAutomaticDebits::class)->assertSuccessful();

    $debit->refresh();

    expect($debit->status)->toBe(AutomaticDebitStatus::Active)
        ->and($debit->failure_count)->toBe(1)
        ->and($debit->last_error)->toContain('Insufficient funds')
        // Roughly 24 hours out — not retried in a tight loop against a bank.
        // Measured forwards from now: Carbon returns a signed difference, so
        // the operands the other way round give a negative.
        ->and(now()->diffInHours($debit->next_run_at))->toBeGreaterThan(22);
});

it('stops and asks for a new card after the retry also fails', function () {
    savedCard($this->customer);
    $plan = debitablePlan($this->customer);
    $debit = $this->debits->enable($this->customer, $plan);

    $this->gateway->declineCharges = true;

    $this->artisan(ChargeDueAutomaticDebits::class)->assertSuccessful();
    $this->travel(25)->hours();
    $this->artisan(ChargeDueAutomaticDebits::class)->assertSuccessful();

    $debit->refresh();

    expect($debit->status)->toBe(AutomaticDebitStatus::NeedsReauthorization)
        ->and($debit->next_run_at)->toBeNull()
        ->and($this->gateway->charges)->toHaveCount(2);

    // And it stays stopped rather than quietly trying a third time.
    $this->travel(25)->hours();
    $this->artisan(ChargeDueAutomaticDebits::class)->assertSuccessful();

    expect($this->gateway->charges)->toHaveCount(2);
});

it('leaves a paused plan alone, which is the pause doing its job', function () {
    savedCard($this->customer);
    $plan = debitablePlan($this->customer);
    $this->debits->enable($this->customer, $plan);

    app(SavingsGoalService::class)->pause($this->customer, $plan);

    $this->artisan(ChargeDueAutomaticDebits::class)->assertSuccessful();

    expect($this->gateway->charges)->toBeEmpty();

    // Skipping a pause is not a failure, so no retry is burned.
    $debit = AutomaticDebit::query()->where('savings_goal_id', $plan->id)->first();
    expect($debit->failure_count)->toBe(0)
        ->and($debit->status)->toBe(AutomaticDebitStatus::Active);
});

it('never charges more than the plan still owes', function () {
    savedCard($this->customer);
    // 40,000 left but a 100,000 instalment.
    $plan = debitablePlan($this->customer, ['paid_kobo' => 460_000]);
    $this->debits->enable($this->customer, $plan);

    $this->artisan(ChargeDueAutomaticDebits::class)->assertSuccessful();

    expect($this->gateway->lastAmountKobo())->toBe(40_000);
});

it('switches itself off once the plan is covered', function () {
    savedCard($this->customer);
    $plan = debitablePlan($this->customer);
    $this->debits->enable($this->customer, $plan);

    $plan->update(['paid_kobo' => 500_000]);

    $this->artisan(ChargeDueAutomaticDebits::class)->assertSuccessful();

    expect($this->gateway->charges)->toBeEmpty()
        ->and(AutomaticDebit::query()->first()->status)->toBe(AutomaticDebitStatus::Cancelled);
});

it('stops when the saved card has been deactivated', function () {
    $card = savedCard($this->customer);
    $plan = debitablePlan($this->customer);
    $this->debits->enable($this->customer, $plan);

    $card->update(['active' => false]);

    $this->artisan(ChargeDueAutomaticDebits::class)->assertSuccessful();

    expect($this->gateway->charges)->toBeEmpty()
        ->and(AutomaticDebit::query()->first()->status)
        ->toBe(AutomaticDebitStatus::NeedsReauthorization);
});

it('exposes the switch over HTTP and refuses it to anyone else', function () {
    savedCard($this->customer);
    $plan = debitablePlan($this->customer);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post("/savings/plans/{$plan->uuid}/automatic-debit")
        ->assertForbidden();

    $this->actingAs($this->customer)
        ->post("/savings/plans/{$plan->uuid}/automatic-debit")
        ->assertRedirect();

    expect(AutomaticDebit::query()->first()->status)->toBe(AutomaticDebitStatus::Active);

    $this->actingAs($this->customer)
        ->delete("/savings/plans/{$plan->uuid}/automatic-debit")
        ->assertRedirect();

    expect(AutomaticDebit::query()->first()->status)->toBe(AutomaticDebitStatus::Cancelled);
});

/*
 * ── The exactly-once guarantee ──
 *
 * The chain that matters for a money system, proven end to end rather than
 * asserted: the debit writes a transaction under a reference, Paystack is
 * charged with that same reference, the signature-verified webhook finds that
 * row, and the plan is credited once — no matter how many times the webhook
 * arrives.
 */
it('credits the plan exactly once, through the webhook, for an automatic charge', function () {
    savedCard($this->customer);
    $plan = debitablePlan($this->customer);
    $this->debits->enable($this->customer, $plan);

    $this->artisan(ChargeDueAutomaticDebits::class)->assertSuccessful();

    $transaction = PaystackTransaction::query()->where('savings_goal_id', $plan->id)->firstOrFail();

    // The reference the scheduler charged under is the one we recorded.
    expect($transaction->paystack_reference)->toStartWith('FMA_')
        ->and($this->gateway->charges[0]['reference'])->toBe($transaction->paystack_reference);

    // Nothing credited yet — the charge alone never moves money.
    expect($plan->refresh()->paid_kobo)->toBe(100_000);

    postWebhook(chargeSuccessPayload($transaction->paystack_reference, 100_000))->assertOk();

    expect($plan->refresh()->paid_kobo)->toBe(200_000)
        ->and($transaction->refresh()->webhook_verified_at)->not->toBeNull();

    // Paystack retries webhooks. Replaying the same event must change nothing.
    postWebhook(chargeSuccessPayload($transaction->paystack_reference, 100_000))->assertOk();
    postWebhook(chargeSuccessPayload($transaction->paystack_reference, 100_000))->assertOk();

    expect($plan->refresh()->paid_kobo)->toBe(200_000);
});

it('ignores a webhook whose reference matches no transaction of ours', function () {
    $plan = debitablePlan($this->customer);

    // A reference we never issued cannot credit anything, even correctly signed.
    postWebhook(chargeSuccessPayload('FMA_not_ours', 100_000))->assertOk();

    expect($plan->refresh()->paid_kobo)->toBe(100_000);
});

it('treats a charge still with the bank as in flight, never as a failure to retry', function () {
    savedCard($this->customer);
    $plan = debitablePlan($this->customer);
    $debit = $this->debits->enable($this->customer, $plan);

    // Paystack accepted it but has not settled it yet.
    $this->gateway->pendingCharges = true;
    $this->artisan(ChargeDueAutomaticDebits::class)->assertSuccessful();

    $debit->refresh();

    // No retry is queued: a retry alongside a live charge is how one
    // instalment gets paid twice.
    expect($debit->failure_count)->toBe(0)
        ->and($debit->status)->toBe(AutomaticDebitStatus::Active)
        ->and($debit->next_run_at->isFuture())->toBeTrue()
        ->and($debit->next_run_at->diffInHours(now()))->toBeLessThan(-23);

    // And the outcome still arrives the only way it can — by webhook.
    $transaction = PaystackTransaction::query()->where('savings_goal_id', $plan->id)->firstOrFail();
    postWebhook(chargeSuccessPayload($transaction->paystack_reference, 100_000))->assertOk();

    expect($plan->refresh()->paid_kobo)->toBe(200_000);
});
