<?php

use App\Models\Setting;
use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\PaystackTransaction;
use App\Modules\Payments\Services\PaymentReconciler;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Contracts\PaymentGatewayContract;
use App\Shared\Enums\PaystackTransactionStatus;
use App\Shared\Enums\SavingsGoalStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\FakePaymentGateway;

/**
 * Finding payments the webhook never told us about.
 *
 * A dropped webhook means the money moved and our records say it did not.
 * The customer is then invited to pay a second time for something they have
 * already paid for — the most expensive bug a payment flow can have, and the
 * one this reconciler exists to make impossible.
 *
 * The tests below are grouped by the three outcomes, because they are not
 * symmetrical: crediting a success is urgent, deleting a dead attempt is
 * housekeeping, and touching anything still in flight is forbidden.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGatewayContract::class, $this->gateway);

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    $this->reconciler = app(PaymentReconciler::class);
});

function reconcilablePlan(User $user, int $targetKobo = 300_000_00): SavingsGoal
{
    return SavingsGoal::query()->create([
        'user_id' => $user->id,
        'target_kobo' => $targetKobo,
        'delivery_fee_kobo' => 0,
        'installments' => 3,
        'installment_kobo' => 100_000_00,
        'paid_kobo' => 0,
        'status' => SavingsGoalStatus::Saving,
    ]);
}

function pendingCharge(User $user, array $attributes = []): PaystackTransaction
{
    // created_at is not fillable, so ageing a row has to be done after the
    // insert — otherwise Eloquent stamps "now" and the sweep's age filter
    // silently sees nothing.
    $createdAt = $attributes['created_at'] ?? null;
    unset($attributes['created_at']);

    $charge = PaystackTransaction::query()->create(array_merge([
        'user_id' => $user->id,
        'purpose' => 'plan_installment',
        'paystack_reference' => 'FMP_'.uniqid(),
        'amount_kobo' => 100_000_00,
        'currency' => 'NGN',
        'status' => PaystackTransactionStatus::Pending,
    ], $attributes));

    if ($createdAt !== null) {
        PaystackTransaction::query()->whereKey($charge->id)->update(['created_at' => $createdAt]);
        $charge->refresh();
    }

    return $charge;
}

// ── A payment that succeeded but never reached us ───────────────────────────

it('credits a plan when Paystack confirms a charge the webhook never delivered', function () {
    $plan = reconcilablePlan($this->customer);
    $charge = pendingCharge($this->customer, ['savings_goal_id' => $plan->id]);

    // The money did arrive; the webhook simply never got here.
    $this->gateway->stageSuccess($charge->paystack_reference, 100_000_00);

    expect($this->reconciler->reconcile($charge))->toBe('settled');

    expect($plan->fresh()->paid_kobo)->toBe(100_000_00)
        ->and($charge->fresh()->status)->toBe(PaystackTransactionStatus::Success)
        ->and($charge->fresh()->webhook_verified_at)->not->toBeNull();
});

it('does not credit the same payment twice when the webhook finally arrives', function () {
    $plan = reconcilablePlan($this->customer);
    $charge = pendingCharge($this->customer, ['savings_goal_id' => $plan->id]);
    $this->gateway->stageSuccess($charge->paystack_reference, 100_000_00);

    $this->reconciler->reconcile($charge);

    // The late webhook lands afterwards.
    app(\App\Modules\Payments\Actions\ProcessPaystackWebhook::class)->handle([
        'event' => 'charge.success',
        'data' => [
            'reference' => $charge->paystack_reference,
            'status' => 'success',
            'amount' => 100_000_00,
            'currency' => 'NGN',
        ],
    ], signatureValid: true);

    expect($plan->fresh()->paid_kobo)->toBe(100_000_00);
});

it('is safe to run twice over the same charge', function () {
    $plan = reconcilablePlan($this->customer);
    $charge = pendingCharge($this->customer, ['savings_goal_id' => $plan->id]);
    $this->gateway->stageSuccess($charge->paystack_reference, 100_000_00);

    $this->reconciler->reconcile($charge);
    $this->reconciler->reconcile($charge->fresh());

    expect($plan->fresh()->paid_kobo)->toBe(100_000_00);
});

it('completes a checkout session whose payment went through unnoticed', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 50_000_00, 'stock_quantity' => 5]);
    $session = CheckoutSession::query()->create([
        'user_id' => $this->customer->id,
        'total_amount_kobo' => 50_000_00,
        'shipping_fee_kobo' => 0,
        'payment_method' => 'card',
        'status' => 'pending',
        'paystack_reference' => 'FMC_orphaned',
        'items_snapshot' => [[
            'product_id' => $product->id, 'quantity' => 1,
            'unit_price_kobo' => 50_000_00, 'cart_item_id' => null,
        ]],
        'delivery_address' => '12 Marina Road',
        'state' => 'Lagos',
        'lga' => 'Eti-Osa',
        'recipient_name' => 'Test Customer',
        'recipient_phone' => '08031234567',
    ]);
    $charge = pendingCharge($this->customer, [
        'purpose' => 'order',
        'checkout_session_id' => $session->id,
        'paystack_reference' => 'FMC_orphaned',
        'amount_kobo' => 50_000_00,
    ]);

    $this->gateway->stageSuccess('FMC_orphaned', 50_000_00);

    expect($this->reconciler->reconcile($charge))->toBe('settled')
        ->and($session->fresh()->status)->toBe('paid')
        ->and(Order::query()->where('customer_id', $this->customer->id)->count())->toBe(1);
});

// ── Attempts that will never become money ───────────────────────────────────

it('removes an attempt Paystack has never heard of', function () {
    $charge = pendingCharge($this->customer);

    // Nothing staged: the customer got the hand-off page and closed it.
    expect($this->reconciler->reconcile($charge))->toBe('removed')
        ->and(PaystackTransaction::query()->whereKey($charge->id)->exists())->toBeFalse();
});

it('removes an attempt Paystack reports as failed or abandoned', function (string $status) {
    $charge = pendingCharge($this->customer);
    $this->gateway->stageStatus($charge->paystack_reference, $status);

    expect($this->reconciler->reconcile($charge))->toBe('removed')
        ->and(PaystackTransaction::query()->whereKey($charge->id)->exists())->toBeFalse();
})->with(['failed', 'abandoned', 'reversed']);

// ── The things it must never do ─────────────────────────────────────────────

it('never deletes an attempt while the bank is still deciding', function (string $status) {
    $charge = pendingCharge($this->customer);
    $this->gateway->stageStatus($charge->paystack_reference, $status);

    expect($this->reconciler->reconcile($charge))->toBe('unresolved')
        ->and(PaystackTransaction::query()->whereKey($charge->id)->exists())->toBeTrue();
})->with(['pending', 'ongoing', 'processing', 'queued']);

it('never deletes anything when Paystack cannot be reached', function () {
    $charge = pendingCharge($this->customer);
    $this->gateway->unreachable = true;

    // A timeout tells us nothing. Concluding "failed" from silence is how a
    // business destroys the evidence that somebody paid it.
    expect($this->reconciler->reconcile($charge))->toBe('unresolved')
        ->and(PaystackTransaction::query()->whereKey($charge->id)->exists())->toBeTrue();
});

it('never touches a charge that has already been credited', function () {
    $charge = pendingCharge($this->customer, [
        'status' => PaystackTransactionStatus::Success,
        'webhook_verified_at' => now(),
    ]);
    $this->gateway->stageStatus($charge->paystack_reference, 'failed');

    expect($this->reconciler->reconcile($charge))->toBe('unresolved')
        ->and(PaystackTransaction::query()->whereKey($charge->id)->exists())->toBeTrue()
        // It never even asked, because a settled payment is not in question.
        ->and($this->gateway->verified)->toBeEmpty();
});

it('refuses to credit a charge for more than was requested', function () {
    $plan = reconcilablePlan($this->customer);
    $charge = pendingCharge($this->customer, ['savings_goal_id' => $plan->id, 'amount_kobo' => 10_000_00]);

    // Paystack reporting a larger amount than we asked for is a bug or a
    // configuration drift, never something to bank silently.
    $this->gateway->stageSuccess($charge->paystack_reference, 999_000_00);

    expect($this->reconciler->reconcile($charge))->toBe('unresolved')
        ->and($plan->fresh()->paid_kobo)->toBe(0);
});

// ── The customer coming back to pay again ───────────────────────────────────

it('does not charge a customer twice for a plan instalment they already paid', function () {
    $plan = reconcilablePlan($this->customer);
    $charge = pendingCharge($this->customer, ['savings_goal_id' => $plan->id]);
    $this->gateway->stageSuccess($charge->paystack_reference, 100_000_00);

    $chargesBefore = count($this->gateway->charges);

    $this->actingAs($this->customer)
        ->post(route('savings.goals.pay', $plan->uuid), ['amount' => 100_000]);

    // Their earlier payment was found and credited; no new charge was started.
    expect($plan->fresh()->paid_kobo)->toBe(100_000_00)
        ->and(count($this->gateway->charges))->toBe($chargesBefore);
});

it('starts a fresh charge with a new reference once the dead attempt is cleared', function () {
    $plan = reconcilablePlan($this->customer);
    $dead = pendingCharge($this->customer, ['savings_goal_id' => $plan->id]);

    $this->actingAs($this->customer)
        ->post(route('savings.goals.pay', $plan->uuid), ['amount' => 100_000]);

    expect(PaystackTransaction::query()->whereKey($dead->id)->exists())->toBeFalse()
        ->and(PaystackTransaction::query()->where('savings_goal_id', $plan->id)->count())->toBe(1)
        ->and(PaystackTransaction::query()->where('savings_goal_id', $plan->id)->value('paystack_reference'))
        ->not->toBe($dead->paystack_reference);
});

// ── The scheduled sweep ─────────────────────────────────────────────────────

it('leaves a charge alone until it is old enough to have settled', function () {
    Setting::set('payments.reconcile_after_minutes', 30, 'payments');
    Setting::flushCache();

    // Somebody who is on Paystack's page right now.
    pendingCharge($this->customer, ['created_at' => now()->subMinute()]);

    expect($this->reconciler->sweep())->toBe(['settled' => 0, 'removed' => 0, 'inFlight' => 0])
        ->and($this->gateway->verified)->toBeEmpty();
});

it('sweeps up everything old enough, crediting and pruning in one pass', function () {
    Setting::set('payments.reconcile_after_minutes', 30, 'payments');
    Setting::flushCache();

    $plan = reconcilablePlan($this->customer);
    $paid = pendingCharge($this->customer, [
        'savings_goal_id' => $plan->id,
        'created_at' => now()->subHours(2),
    ]);
    $abandoned = pendingCharge($this->customer, ['created_at' => now()->subHours(2)]);
    $inFlight = pendingCharge($this->customer, ['created_at' => now()->subHours(2)]);

    $this->gateway->stageSuccess($paid->paystack_reference, 100_000_00);
    $this->gateway->stageStatus($inFlight->paystack_reference, 'ongoing');

    expect($this->reconciler->sweep())->toBe(['settled' => 1, 'removed' => 1, 'inFlight' => 1]);

    expect($plan->fresh()->paid_kobo)->toBe(100_000_00)
        ->and(PaystackTransaction::query()->whereKey($abandoned->id)->exists())->toBeFalse()
        ->and(PaystackTransaction::query()->whereKey($inFlight->id)->exists())->toBeTrue();
});

it('only reconciles the customer whose payment is being started', function () {
    $mine = reconcilablePlan($this->customer);
    $stranger = User::factory()->create();
    $theirs = pendingCharge($stranger);

    $this->reconciler->reconcileSubject($this->customer->id, ['savings_goal_id' => $mine->id]);

    expect(PaystackTransaction::query()->whereKey($theirs->id)->exists())->toBeTrue()
        ->and($this->gateway->verified)->not->toContain($theirs->paystack_reference);
});

it('reports what it did so the scheduled run is auditable', function () {
    $result = $this->reconciler->sweep();

    expect($result)->toHaveKeys(['settled', 'removed', 'inFlight']);
});
