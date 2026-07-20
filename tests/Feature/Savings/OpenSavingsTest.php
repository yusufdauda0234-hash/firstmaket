<?php

use App\Models\User;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Savings\Models\OpenSaving;
use App\Modules\Savings\Services\OpenSavingsService;
use App\Modules\Wallet\Models\Wallet;
use App\Modules\Wallet\Services\WalletService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;

/**
 * Sprint 5 QA (docs/firstmarket_Implementation_Plan.md): one Open Savings pot
 * per customer, funded only from webhook-verified wallet money, and no cash
 * exit anywhere in the savings engine.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);
});

function fundWallet(User $user, int $amountKobo): void
{
    app(WalletService::class)->creditDeposit($user, $amountKobo, 'TEST-DEP-'.fake()->unique()->uuid());
}

it('creates exactly one Open Savings pot per customer', function () {
    $service = app(OpenSavingsService::class);

    $first = $service->getOrCreate($this->customer);
    $second = $service->getOrCreate($this->customer);

    expect($first->id)->toBe($second->id)
        ->and(OpenSaving::query()->where('user_id', $this->customer->id)->count())->toBe(1);
});

it('allocates wallet money into Open Savings with a ledger debit', function () {
    fundWallet($this->customer, 100_000_00);

    $this->actingAs($this->customer)
        ->post(route('savings.open.allocate'), ['amount_naira' => 40_000])
        ->assertRedirect();

    $wallet = Wallet::query()->where('user_id', $this->customer->id)->firstOrFail();
    $pot = OpenSaving::query()->where('user_id', $this->customer->id)->firstOrFail();

    expect($wallet->balance_kobo)->toBe(60_000_00)
        ->and($pot->balance_kobo)->toBe(40_000_00);

    $this->assertDatabaseHas('wallet_transactions', [
        'user_id' => $this->customer->id,
        'type' => 'open_savings_allocation',
        'direction' => 'debit',
        'amount_kobo' => 40_000_00,
        'balance_after_kobo' => 60_000_00,
    ]);
});

it('rejects an allocation larger than the wallet balance', function () {
    fundWallet($this->customer, 10_000_00);

    $this->actingAs($this->customer)
        ->from(route('savings.index'))
        ->post(route('savings.open.allocate'), ['amount_naira' => 50_000])
        ->assertRedirect(route('savings.index'))
        ->assertSessionHasErrors('amount');

    expect(OpenSaving::query()->where('user_id', $this->customer->id)->value('balance_kobo') ?? 0)->toBe(0);
});

it('has no withdrawal route, controller, or action anywhere', function () {
    $withdrawalish = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains(strtolower($route->uri()), 'withdraw')
            || str_contains(strtolower($route->getActionName()), 'withdraw'));

    expect($withdrawalish)->toBeEmpty();
});
