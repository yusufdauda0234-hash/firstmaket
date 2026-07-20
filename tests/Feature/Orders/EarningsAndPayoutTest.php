<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\DeliveryService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\PreparationService;
use App\Modules\Savings\Services\PlanService;
use App\Modules\Vendor\Models\VendorEarning;
use App\Modules\Vendor\Services\BankAccountService;
use App\Modules\Vendor\Services\EarningsService;
use App\Modules\Vendor\Services\PayoutService;
use App\Modules\Wallet\Models\Wallet;
use App\Modules\Wallet\Services\WalletService;
use App\Shared\Contracts\BankAccountResolverContract;
use App\Shared\Enums\IdentityStatus;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\PayoutBatchStatus;
use App\Shared\Enums\PayoutItemStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 6 QA: earnings credit exactly once on confirmed delivery, the
 * auto-confirm window, payout batch math against the ledger, failed
 * transfers never debiting, and total separation from customer wallets.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    // A fake resolver that always verifies (payout tests swap it as needed).
    app()->instance(BankAccountResolverContract::class, new class implements BankAccountResolverContract
    {
        public function resolveAccountName(string $accountNumber, string $bankCode): ?string
        {
            return 'PRIME ELECTRONICS LTD';
        }

        public function createTransferRecipient(string $name, string $accountNumber, string $bankCode): ?string
        {
            return 'RCP_test123';
        }
    });

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create([
        'user_id' => $this->customer->id,
        'identity_status' => IdentityStatus::Verified,
    ]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Administrator');

    $this->finance = User::factory()->create();
    $this->finance->assignRole('Finance Officer');

    $this->product = Product::factory()->approved()->create(['price_kobo' => 100_000_00]);
    $this->vendorUser = $this->product->vendor->user;
    $this->vendorUser->assignRole('Vendor');
});

/** Run one order to Delivered (unconfirmed) and return it. */
function deliveredOrder($test): Order
{
    app(WalletService::class)->creditDeposit($test->customer, $test->product->price_kobo, 'TEST-DEP-'.fake()->unique()->uuid());
    $plan = app(PlanService::class)->payAtOnce($test->customer, $test->product);
    $order = app(OrderService::class)->createFromPlan($test->customer, $plan, '12 Marina Road', 'Lagos', 'Eti-Osa');
    app(OrderService::class)->confirm($test->admin, $order);
    app(PreparationService::class)->markReadyForPickup($test->vendorUser, $order->refresh());

    $logistics = User::factory()->create();
    $logistics->assignRole('Logistics Personnel');
    app(DeliveryService::class)->assign($test->admin, $order->refresh(), $logistics);
    foreach ([OrderStatus::Packed, OrderStatus::Shipped, OrderStatus::OutForDelivery, OrderStatus::Delivered] as $step) {
        app(DeliveryService::class)->updateStatus($logistics, $order->refresh(), $step);
    }

    return $order->refresh();
}

it('credits vendor earnings exactly once when the customer confirms receipt', function () {
    $order = deliveredOrder($this);

    expect(VendorEarning::query()->where('vendor_id', $order->vendor_id)->count())->toBe(0);

    $this->actingAs($this->customer)
        ->post(route('orders.confirm-receipt', $order->uuid))
        ->assertRedirect();

    // Second confirm is a no-op (idempotent).
    app(OrderService::class)->confirmDelivery($this->customer, $order->refresh());

    $earnings = VendorEarning::query()->where('vendor_id', $order->vendor_id)->get();

    expect($earnings)->toHaveCount(1)
        ->and($earnings->first()->amount_kobo)->toBe($order->vendor_earning_amount_kobo)
        ->and($order->refresh()->earnings_credited_at)->not->toBeNull();
});

it('auto-confirms delivered orders after the window and credits earnings', function () {
    $order = deliveredOrder($this);
    $order->forceFill(['delivered_at' => now()->subDays(4)])->save();

    $this->artisan('orders:auto-confirm')->assertSuccessful();

    expect($order->refresh()->delivery_confirmed_at)->not->toBeNull()
        ->and(VendorEarning::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('does not credit earnings before delivery is confirmed', function () {
    $order = deliveredOrder($this);
    $order->forceFill(['delivered_at' => now()->subHours(2)])->save();

    // Window (3 days) has not passed — auto-confirm must skip it.
    $this->artisan('orders:auto-confirm')->assertSuccessful();

    expect($order->refresh()->delivery_confirmed_at)->toBeNull()
        ->and(VendorEarning::query()->where('order_id', $order->id)->exists())->toBeFalse();
});

it('generates a payout batch whose items equal each vendor ledger balance and pays without touching wallets', function () {
    $order = deliveredOrder($this);
    app(OrderService::class)->confirmDelivery($this->customer, $order);

    // Verified active bank account through the fake resolver.
    app(BankAccountService::class)->setAccount(
        $this->vendorUser, $order->vendor, '058', 'GTBank', '0123456789',
    );

    $walletTotalBefore = (int) Wallet::query()->sum('balance_kobo');

    $batch = app(PayoutService::class)->generateBatch($this->finance);

    expect($batch->status)->toBe(PayoutBatchStatus::PendingApproval)
        ->and($batch->items)->toHaveCount(1)
        ->and($batch->total_amount_kobo)->toBe($order->vendor_earning_amount_kobo)
        ->and($batch->items->first()->amount_kobo)
        ->toBe(app(EarningsService::class)->balanceKobo($order->vendor));

    app(PayoutService::class)->approveBatch($this->finance, $batch);

    $item = $batch->items()->first();
    app(PayoutService::class)->markItemPaid($this->finance, $item, 'TRF_abc123');

    expect($item->refresh()->status)->toBe(PayoutItemStatus::Paid)
        ->and(app(EarningsService::class)->balanceKobo($order->vendor))->toBe(0)
        // Customer wallets are completely untouched by vendor payouts.
        ->and((int) Wallet::query()->sum('balance_kobo'))->toBe($walletTotalBefore);

    // Ledger shows the negative payout row.
    $this->assertDatabaseHas('vendor_earnings', [
        'vendor_id' => $order->vendor_id,
        'type' => 'payout',
        'amount_kobo' => -$order->vendor_earning_amount_kobo,
        'payout_item_id' => $item->id,
    ]);
});

it('skips vendors without a verified bank account when generating a batch', function () {
    $order = deliveredOrder($this);
    app(OrderService::class)->confirmDelivery($this->customer, $order);

    $batch = app(PayoutService::class)->generateBatch($this->finance);

    expect($batch->items)->toHaveCount(0)
        ->and($batch->total_amount_kobo)->toBe(0)
        // Balance stays intact for the next run.
        ->and(app(EarningsService::class)->balanceKobo($order->vendor))
        ->toBe($order->vendor_earning_amount_kobo);
});

it('keeps the ledger intact when a transfer fails', function () {
    $order = deliveredOrder($this);
    app(OrderService::class)->confirmDelivery($this->customer, $order);
    app(BankAccountService::class)->setAccount($this->vendorUser, $order->vendor, '058', 'GTBank', '0123456789');

    $batch = app(PayoutService::class)->generateBatch($this->finance);
    app(PayoutService::class)->approveBatch($this->finance, $batch);
    $item = $batch->items()->first();

    app(PayoutService::class)->markItemFailed($this->finance, $item, 'Transfer bounced');

    expect($item->refresh()->status)->toBe(PayoutItemStatus::Failed)
        ->and($batch->refresh()->status)->toBe(PayoutBatchStatus::Failed)
        // No payout debit was written — the balance is fully retryable.
        ->and(app(EarningsService::class)->balanceKobo($order->vendor))
        ->toBe($order->vendor_earning_amount_kobo)
        ->and(VendorEarning::query()->where('type', 'payout')->exists())->toBeFalse();
});

it('rejects a bank account the provider cannot verify', function () {
    app()->instance(BankAccountResolverContract::class, new class implements BankAccountResolverContract
    {
        public function resolveAccountName(string $accountNumber, string $bankCode): ?string
        {
            return null;
        }

        public function createTransferRecipient(string $name, string $accountNumber, string $bankCode): ?string
        {
            return null;
        }
    });

    $order = deliveredOrder($this);

    app(BankAccountService::class)->setAccount($this->vendorUser, $order->vendor, '058', 'GTBank', '0000000000');
})->throws(ValidationException::class);

it('never includes another vendor’s earnings in a payout item', function () {
    // Vendor A earns from one order.
    $orderA = deliveredOrder($this);
    app(OrderService::class)->confirmDelivery($this->customer, $orderA);
    app(BankAccountService::class)->setAccount($this->vendorUser, $orderA->vendor, '058', 'GTBank', '0123456789');

    // Vendor B earns from a separate order for a different product.
    $productB = Product::factory()->approved()->create(['price_kobo' => 40_000_00]);
    $vendorUserB = $productB->vendor->user;
    $vendorUserB->assignRole('Vendor');
    app(WalletService::class)->creditDeposit($this->customer, 40_000_00, 'TEST-DEP-'.fake()->unique()->uuid());
    $planB = app(PlanService::class)->payAtOnce($this->customer, $productB);
    $orderB = app(OrderService::class)->createFromPlan($this->customer, $planB, '12 Marina Road', 'Lagos', 'Eti-Osa');
    app(OrderService::class)->confirm($this->admin, $orderB);
    app(PreparationService::class)->markReadyForPickup($vendorUserB, $orderB->refresh());
    $logistics = User::factory()->create();
    $logistics->assignRole('Logistics Personnel');
    app(DeliveryService::class)->assign($this->admin, $orderB->refresh(), $logistics);
    foreach ([OrderStatus::Packed, OrderStatus::Shipped, OrderStatus::OutForDelivery, OrderStatus::Delivered] as $step) {
        app(DeliveryService::class)->updateStatus($logistics, $orderB->refresh(), $step);
    }
    app(OrderService::class)->confirmDelivery($this->customer, $orderB->refresh());
    app(BankAccountService::class)->setAccount($vendorUserB, $orderB->vendor, '044', 'Access Bank', '9876543210');

    $batch = app(PayoutService::class)->generateBatch($this->finance);

    expect($batch->items)->toHaveCount(2);

    $itemA = $batch->items->firstWhere('vendor_id', $orderA->vendor_id);
    $itemB = $batch->items->firstWhere('vendor_id', $orderB->vendor_id);

    expect($itemA->amount_kobo)->toBe($orderA->vendor_earning_amount_kobo)
        ->and($itemB->amount_kobo)->toBe($orderB->vendor_earning_amount_kobo)
        ->and($batch->total_amount_kobo)
        ->toBe($orderA->vendor_earning_amount_kobo + $orderB->vendor_earning_amount_kobo);
});
