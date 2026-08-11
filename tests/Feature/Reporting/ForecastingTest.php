<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\Wishlist;
use App\Modules\Reporting\Services\ReportingService;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\SavingsGoalStatus;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Phase 2D forecasting.
 *
 * The requirement with teeth is the privacy one: forecasting must not expose
 * customer identity. These reports are aggregates by construction — the
 * identity is never selected, so there is nothing to leak rather than
 * something filtered out afterwards.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->reports = app(ReportingService::class);
    $this->category = Category::factory()->create();
});

it('counts wishlist demand per product without naming anybody', function () {
    $product = Product::factory()->approved()->create(['category_id' => $this->category->id]);

    foreach (range(1, 3) as $ignored) {
        Wishlist::query()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->id,
        ]);
    }

    $report = $this->reports->wishlistDemand();
    $row = $report['rows'][0];

    expect($row['savedBy'])->toBe(3)
        ->and($row['product'])->toBe($product->name);

    // Nothing that could identify a customer is present at all.
    $encoded = json_encode($report);
    expect($encoded)->not->toContain('user_id')
        ->and($encoded)->not->toContain('email')
        ->and(array_keys($row))->not->toContain('userId');
});

it('reports demand the current stock cannot serve', function () {
    $product = Product::factory()->approved()->create([
        'category_id' => $this->category->id,
        'stock_quantity' => 1,
    ]);

    foreach (range(1, 4) as $ignored) {
        Wishlist::query()->create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $product->id,
        ]);
    }

    // Four people waiting, one on the shelf — the number worth acting on.
    expect($this->reports->wishlistDemand()['rows'][0]['shortfall'])->toBe(3);
});

it('projects when a running plan will finish', function () {
    SavingsGoal::query()->create([
        'user_id' => User::factory()->create()->id,
        'target_kobo' => 500_000,
        'delivery_fee_kobo' => 0,
        'cadence' => PlanCadence::Monthly,
        'installments' => 5,
        'payments_made' => 3,
        'installment_kobo' => 100_000,
        'paid_kobo' => 300_000,
        'status' => SavingsGoalStatus::Saving,
        'next_due_at' => now()->addDays(5),
    ]);

    $report = $this->reports->expectedCompletions();

    expect($report['count'])->toBe(1)
        ->and($report['totalRemainingKobo'])->toBe(200_000)
        // 200,000 left at 100,000 a month is two more payments.
        ->and($report['rows'][0]['paymentsLeft'])->toBe(2);
});

it('keeps customer identity out of the completions forecast', function () {
    SavingsGoal::query()->create([
        'user_id' => User::factory()->create(['name' => 'Yakubu Dauda'])->id,
        'target_kobo' => 500_000,
        'delivery_fee_kobo' => 0,
        'cadence' => PlanCadence::Monthly,
        'installments' => 5,
        'payments_made' => 1,
        'installment_kobo' => 100_000,
        'paid_kobo' => 100_000,
        'status' => SavingsGoalStatus::Saving,
        'next_due_at' => now()->addDays(3),
    ]);

    $encoded = json_encode($this->reports->expectedCompletions());

    expect($encoded)->not->toContain('Yakubu Dauda')
        ->and($encoded)->not->toContain('userId');
});

it('leaves plans nobody has paid into out of the forecast', function () {
    SavingsGoal::query()->create([
        'user_id' => User::factory()->create()->id,
        'target_kobo' => 500_000,
        'delivery_fee_kobo' => 0,
        'cadence' => PlanCadence::Monthly,
        'installments' => 5,
        'payments_made' => 0,
        'installment_kobo' => 100_000,
        'paid_kobo' => 0,
        'status' => SavingsGoalStatus::Saving,
        'next_due_at' => now()->addDays(3),
    ]);

    // A plan with nothing paid in is not evidence of anything yet.
    expect($this->reports->expectedCompletions()['count'])->toBe(0);
});
