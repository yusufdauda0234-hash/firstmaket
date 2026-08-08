<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Savings\Models\PlanTerm;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Services\SavingsGoalService;
use App\Modules\Savings\Services\SavingsService;
use App\Shared\Contracts\PaymentGatewayContract;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\SavingsGoalStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakePaymentGateway;

/**
 * Switching a running plan to a different item.
 *
 * The money stays on the plan — this is not cancel-then-create — but the new
 * item is priced at today's price, because a frozen fridge price cannot
 * honestly be carried onto a television.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);

    $this->fridge = Product::factory()->approved()->create(['price_kobo' => 120_000_00, 'stock_quantity' => 10]);
});

function switchTerm(int $months = 3, int $dueDays = 30, int $minTargetKobo = 0): PlanTerm
{
    return PlanTerm::query()->create([
        'name' => 'Monthly over '.$months.' ('.$dueDays.'d)',
        'cadence' => PlanCadence::Monthly,
        'duration_months' => $months,
        'min_target_kobo' => $minTargetKobo,
        'first_payment_due_days' => $dueDays,
        'missed_payments_allowed' => 3,
        'is_active' => true,
    ]);
}

function switchPlan(User $customer, Product $product, PlanTerm $term): SavingsGoal
{
    return app(SavingsGoalService::class)->createFromLines(
        $customer,
        collect([['cartItemId' => null, 'product' => $product, 'quantity' => 1]]),
        ['delivery_address' => '12 Marina Road', 'state' => 'Lagos', 'lga' => 'Eti-Osa'],
        $term,
    );
}

/** @param  array<int, array{product: Product, quantity: int}>  $lines */
function doSwitch(User $customer, SavingsGoal $goal, array $lines, ?PlanTerm $term = null): SavingsGoal
{
    return app(SavingsGoalService::class)->switchTo($customer, $goal, collect($lines), $term);
}

// ── The money stays put ─────────────────────────────────────────────────

it('carries the payments across without touching credit', function () {
    $goal = switchPlan($this->customer, $this->fridge, switchTerm());
    app(SavingsGoalService::class)->recordPayment($this->customer, $goal, 30_000_00, reference: 'PAY-1');

    $tv = Product::factory()->approved()->create(['price_kobo' => 200_000_00]);
    $goal = doSwitch($this->customer, $goal->refresh(), [['product' => $tv, 'quantity' => 1]]);

    expect($goal->paid_kobo)->toBe(30_000_00)
        ->and($goal->target_kobo)->toBe(planTarget(200_000_00))
        ->and($goal->remainingKobo())->toBe(planTarget(200_000_00) - 30_000_00)
        // Never round-tripped through credit.
        ->and(app(SavingsService::class)->creditKobo($this->customer))->toBe(0);
});

it('keeps the same plan, not a new one', function () {
    $goal = switchPlan($this->customer, $this->fridge, switchTerm());
    $uuid = $goal->uuid;

    $tv = Product::factory()->approved()->create(['price_kobo' => 90_000_00]);
    $goal = doSwitch($this->customer, $goal, [['product' => $tv, 'quantity' => 1]]);

    expect($goal->uuid)->toBe($uuid)
        ->and($goal->status)->toBe(SavingsGoalStatus::Saving)
        ->and(SavingsGoal::query()->count())->toBe(1);
});

it('prices the new item at today price, not the old lock', function () {
    $goal = switchPlan($this->customer, $this->fridge, switchTerm());

    // Stock is pinned: the factory rolls 1-20, so buying two of an
    // unpinned product failed roughly one run in twenty.
    $tv = Product::factory()->approved()->create(['price_kobo' => 80_000_00, 'stock_quantity' => 5]);
    $tv->update(['price_kobo' => 95_000_00]);

    $goal = doSwitch($this->customer, $goal, [['product' => $tv, 'quantity' => 2]]);

    expect($goal->target_kobo)->toBe(planTarget(190_000_00))
        ->and($goal->items()->first()->unit_price_kobo)->toBe(95_000_00);
});

// ── The four outcomes ───────────────────────────────────────────────────

it('leaves a balance to pay when the new item costs more', function () {
    $goal = switchPlan($this->customer, $this->fridge, switchTerm());
    app(SavingsGoalService::class)->recordPayment($this->customer, $goal, 30_000_00, reference: 'PAY-1');

    $tv = Product::factory()->approved()->create(['price_kobo' => 210_000_00]);
    $goal = doSwitch($this->customer, $goal->refresh(), [['product' => $tv, 'quantity' => 1]]);

    // What is left after the ₦30,000 already paid, over 3 monthly
    // payments. Delivery is inside the target, so it is spread too.
    expect($goal->isCovered())->toBeFalse()
        ->and($goal->installment_kobo)
        ->toBe((int) ceil((planTarget(210_000_00) - 30_000_00) / 3));
});

it('completes the plan when what was paid already covers the new item', function () {
    $goal = switchPlan($this->customer, $this->fridge, switchTerm());
    app(SavingsGoalService::class)
        ->recordPayment($this->customer, $goal, planTarget(100_000_00), reference: 'PAY-1');

    $kettle = Product::factory()->approved()->create(['price_kobo' => 100_000_00]);
    $goal = doSwitch($this->customer, $goal->refresh(), [['product' => $kettle, 'quantity' => 1]]);

    expect($goal->isCovered())->toBeTrue()
        ->and($goal->next_due_at)->toBeNull();
});

it('turns the overshoot into credit rather than stranding it', function () {
    $goal = switchPlan($this->customer, $this->fridge, switchTerm());
    app(SavingsGoalService::class)->recordPayment($this->customer, $goal, 100_000_00, reference: 'PAY-1');

    $kettle = Product::factory()->approved()->create(['price_kobo' => 60_000_00]);
    $goal = doSwitch($this->customer, $goal->refresh(), [['product' => $kettle, 'quantity' => 1]]);

    expect($goal->paid_kobo)->toBe(planTarget(60_000_00))
        ->and($goal->isCovered())->toBeTrue()
        // Everything above the new target is credit, never cash.
        ->and(app(SavingsService::class)->creditKobo($this->customer))
        ->toBe(100_000_00 - planTarget(60_000_00));
});

it('demands a term the new total actually clears', function () {
    // The plan runs on a term needing ₦100,000; the new item is worth less.
    $goal = switchPlan($this->customer, $this->fridge, switchTerm(minTargetKobo: 100_000_00));

    $kettle = Product::factory()->approved()->create(['price_kobo' => 20_000_00]);

    doSwitch($this->customer, $goal, [['product' => $kettle, 'quantity' => 1]]);
})->throws(ValidationException::class);

it('accepts a term chosen to fit the new total', function () {
    $goal = switchPlan($this->customer, $this->fridge, switchTerm(minTargetKobo: 100_000_00));
    $kettle = Product::factory()->approved()->create(['price_kobo' => 20_000_00]);

    $goal = doSwitch($this->customer, $goal, [['product' => $kettle, 'quantity' => 1]], switchTerm(6));

    expect($goal->target_kobo)->toBe(planTarget(20_000_00))
        ->and($goal->duration_months)->toBe(6);
});

// ── Limits and guards ───────────────────────────────────────────────────

it('allows two switches and refuses the third', function () {
    $goal = switchPlan($this->customer, $this->fridge, switchTerm());
    $a = Product::factory()->approved()->create(['price_kobo' => 90_000_00]);
    $b = Product::factory()->approved()->create(['price_kobo' => 80_000_00]);
    $c = Product::factory()->approved()->create(['price_kobo' => 70_000_00]);

    $goal = doSwitch($this->customer, $goal, [['product' => $a, 'quantity' => 1]]);
    $goal = doSwitch($this->customer, $goal, [['product' => $b, 'quantity' => 1]]);

    expect($goal->switch_count)->toBe(2);

    doSwitch($this->customer, $goal, [['product' => $c, 'quantity' => 1]]);
})->throws(ValidationException::class);

it('refuses an item that is not on sale', function () {
    $goal = switchPlan($this->customer, $this->fridge, switchTerm());
    $draft = Product::factory()->create(['status' => ProductStatus::Draft, 'price_kobo' => 50_000_00]);

    doSwitch($this->customer, $goal, [['product' => $draft, 'quantity' => 1]]);
})->throws(ValidationException::class);

it('refuses more units than there are in stock', function () {
    $goal = switchPlan($this->customer, $this->fridge, switchTerm());
    $scarce = Product::factory()->approved()->create(['price_kobo' => 10_000_00, 'stock_quantity' => 2]);

    doSwitch($this->customer, $goal, [['product' => $scarce, 'quantity' => 3]]);
})->throws(ValidationException::class);

it('refuses to switch somebody else plan', function () {
    $goal = switchPlan($this->customer, $this->fridge, switchTerm());
    $tv = Product::factory()->approved()->create(['price_kobo' => 90_000_00]);

    doSwitch(User::factory()->create(), $goal, [['product' => $tv, 'quantity' => 1]]);
})->throws(ValidationException::class);

it('refuses to switch a settled plan', function () {
    $goal = switchPlan($this->customer, $this->fridge, switchTerm());
    $goal->forceFill(['status' => SavingsGoalStatus::Cancelled])->save();
    $tv = Product::factory()->approved()->create(['price_kobo' => 90_000_00]);

    doSwitch($this->customer, $goal->refresh(), [['product' => $tv, 'quantity' => 1]]);
})->throws(ValidationException::class);

it('refuses an empty basket', function () {
    $goal = switchPlan($this->customer, $this->fridge, switchTerm());

    app(SavingsGoalService::class)->switchTo($this->customer, $goal, collect());
})->throws(ValidationException::class);

// ── Over HTTP ───────────────────────────────────────────────────────────

it('switches from the plan page', function () {
    $goal = switchPlan($this->customer, $this->fridge, switchTerm());
    $tv = Product::factory()->approved()->create(['price_kobo' => 90_000_00]);

    $this->actingAs($this->customer)
        ->post(route('savings.goals.switch', $goal->uuid), [
            'items' => [['product_uuid' => $tv->uuid, 'quantity' => 1]],
        ])
        ->assertSessionDoesntHaveErrors()
        ->assertRedirect();

    expect($goal->refresh()->target_kobo)->toBe(planTarget(90_000_00));
});

it('collects the shortfall when the term charges up front', function () {
    $gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGatewayContract::class, $gateway);

    // Up-front term, so switching to something dearer asks for money now.
    $goal = switchPlan($this->customer, $this->fridge, switchTerm(dueDays: 0));
    app(SavingsGoalService::class)->recordPayment($this->customer, $goal, 30_000_00, reference: 'PAY-1');

    $tv = Product::factory()->approved()->create(['price_kobo' => 210_000_00]);

    $this->actingAs($this->customer)
        ->post(route('savings.goals.switch', $goal->refresh()->uuid), [
            'items' => [['product_uuid' => $tv->uuid, 'quantity' => 1]],
        ])
        ->assertSessionDoesntHaveErrors();

    // What is left of the new target after the ₦30,000 already paid,
    // over 3 payments — one instalment collected now.
    expect($gateway->lastAmountKobo())
        ->toBe((int) ceil((planTarget(210_000_00) - 30_000_00) / 3));
});

it('will not switch another customer plan over HTTP', function () {
    $goal = switchPlan($this->customer, $this->fridge, switchTerm());
    $tv = Product::factory()->approved()->create(['price_kobo' => 90_000_00]);

    $stranger = User::factory()->create();
    $stranger->assignRole('Customer');

    $this->actingAs($stranger)
        ->post(route('savings.goals.switch', $goal->uuid), [
            'items' => [['product_uuid' => $tv->uuid, 'quantity' => 1]],
        ])
        ->assertForbidden();
});

it('ignores any price sent by the client', function () {
    $goal = switchPlan($this->customer, $this->fridge, switchTerm());
    $tv = Product::factory()->approved()->create(['price_kobo' => 90_000_00]);

    // The classic tampering attempt: a price in the payload.
    $this->actingAs($this->customer)
        ->post(route('savings.goals.switch', $goal->uuid), [
            'items' => [['product_uuid' => $tv->uuid, 'quantity' => 1, 'price_kobo' => 1]],
        ])
        ->assertSessionDoesntHaveErrors();

    expect($goal->refresh()->target_kobo)->toBe(planTarget(90_000_00));
});

it('keeps the locked price on an item the customer did not swap', function () {
    // A plan holding two items. The customer swaps one out and keeps the
    // other, so only the swapped one should be re-priced.
    $tv = Product::factory()->approved()->create(['price_kobo' => 50_000_00]);
    $goal = app(SavingsGoalService::class)->createFromLines(
        $this->customer,
        collect([
            ['cartItemId' => null, 'product' => $this->fridge, 'quantity' => 1],
            ['cartItemId' => null, 'product' => $tv, 'quantity' => 1],
        ]),
        ['delivery_address' => '12 Marina Road', 'state' => 'Lagos', 'lga' => 'Eti-Osa'],
        switchTerm(),
    );

    $lockedFridgePrice = $this->fridge->price_kobo;

    // The kept item gets dearer in the catalogue after the plan started.
    $this->fridge->forceFill(['price_kobo' => $lockedFridgePrice * 2])->save();

    $replacement = Product::factory()->approved()->create(['price_kobo' => 10_000_00]);

    $goal = doSwitch($this->customer, $goal, [
        ['product' => $this->fridge, 'quantity' => 1],
        ['product' => $replacement, 'quantity' => 1],
    ]);

    $kept = $goal->items()->where('product_id', $this->fridge->id)->firstOrFail();
    $added = $goal->items()->where('product_id', $replacement->id)->firstOrFail();

    expect($kept->unit_price_kobo)->toBe($lockedFridgePrice)
        ->and($added->unit_price_kobo)->toBe(10_000_00)
        ->and($goal->target_kobo)->toBe(planTarget($lockedFridgePrice + 10_000_00));
});

it('prices a genuinely new item at today price', function () {
    $goal = switchPlan($this->customer, $this->fridge, switchTerm());

    $replacement = Product::factory()->approved()->create(['price_kobo' => 20_000_00]);
    $replacement->forceFill(['price_kobo' => 25_000_00])->save();

    $goal = doSwitch($this->customer, $goal, [['product' => $replacement, 'quantity' => 1]]);

    // Nothing was kept, so nothing carries a lock across.
    expect($goal->target_kobo)->toBe(planTarget(25_000_00));
});
