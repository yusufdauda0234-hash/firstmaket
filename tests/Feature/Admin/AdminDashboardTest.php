<?php

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\DisplayCurrency;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\DeliveryRate;
use App\Modules\Orders\Models\Order;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\ProductStatus;
use App\Shared\Enums\UserType;
use App\Shared\Enums\VendorStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * The administrator's home screen.
 *
 * It answers "what needs me today", so the things worth pinning are that the
 * counts are real, that nobody is shown a queue they cannot act on, and that
 * the setup checklist gets out of the way once the work is done.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

function dashboardUser(string $role): User
{
    $user = User::factory()->create([
        'user_type' => UserType::Staff,
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

function dashboardUrl(): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/';
}

/** Everything the setup checklist asks for. */
function finishSetup(): void
{
    Category::factory()->create();
    DeliveryRate::query()->create([
        'state' => null,
        'fee_kobo' => 1_000_00 + 500_00,
        'free_threshold_kobo' => 0,
        'is_active' => true,
    ]);
    DisplayCurrency::query()->firstOrCreate(
        ['code' => 'NGN'],
        ['name' => 'Naira', 'symbol' => '₦', 'units_per_naira' => '1.000000', 'decimals' => 2, 'is_active' => true],
    );
    testPlanTerm();

    // ->approved() approves the LISTING, not the seller behind it, so the
    // vendor has to be moved separately or the checklist stays open on it.
    $product = Product::factory()->approved()->create();
    $product->vendor->update(['status' => VendorStatus::Approved]);
}

// ── Who gets which dashboard ────────────────────────────────────────────

it('gives an administrator the overview', function () {
    $this->actingAs(dashboardUser('Administrator'))
        ->get(dashboardUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Dashboard')
            ->has('queues')
            ->has('setup')
            ->has('figures', 4)
            ->has('recentOrders'));
});

it('gives a courier their round instead', function () {
    $this->actingAs(makeCourier())
        ->get(dashboardUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Logistics/Dashboard'));
});

// ── Only what you can act on ────────────────────────────────────────────

it('hides a queue the administrator has no permission for', function () {
    // Payout approval belongs to Finance, not Administrator. A count you
    // cannot clear is noise, not information.
    $this->actingAs(dashboardUser('Administrator'))
        ->get(dashboardUrl())
        ->assertInertia(fn ($page) => $page->where(
            'queues',
            fn ($queues) => collect($queues)->doesntContain(fn ($q) => $q['key'] === 'payouts'),
        ));
});

it('shows the payout queue to a super administrator', function () {
    // Gate::before grants every ability, so they see the whole board.
    $this->actingAs(dashboardUser('Super Administrator'))
        ->get(dashboardUrl())
        ->assertInertia(fn ($page) => $page->where(
            'queues',
            fn ($queues) => collect($queues)->contains(fn ($q) => $q['key'] === 'payouts'),
        ));
});

// ── The counts are real ─────────────────────────────────────────────────

it('counts vendors and listings waiting for a decision', function () {
    Product::factory()->count(3)->create(['status' => ProductStatus::PendingApproval]);
    Product::factory()->create()->vendor->update(['status' => VendorStatus::Pending]);

    $this->actingAs(dashboardUser('Administrator'))
        ->get(dashboardUrl())
        ->assertInertia(function ($page) {
            $queues = collect($page->toArray()['props']['queues']);

            expect($queues->firstWhere('key', 'products')['count'])->toBe(3)
                ->and($queues->firstWhere('key', 'vendors')['count'])->toBeGreaterThanOrEqual(1);
        });
});

it('flags a rejected order as needing a decision', function () {
    $product = Product::factory()->approved()->create();
    Order::factory()->create([
        'vendor_id' => $product->vendor_id,
        'product_id' => $product->id,
        'status' => OrderStatus::VendorRejected,
    ]);

    // A customer has paid for something the vendor cannot supply. Nothing
    // moves until somebody decides, so it is not an ordinary queue.
    $this->actingAs(dashboardUser('Administrator'))
        ->get(dashboardUrl())
        ->assertInertia(function ($page) {
            $rejected = collect($page->toArray()['props']['queues'])->firstWhere('key', 'rejected');

            expect($rejected['count'])->toBe(1)
                ->and($rejected['urgent'] ?? false)->toBeTrue();
        });
});

// ── The setup checklist ─────────────────────────────────────────────────

it('lists what is still unconfigured on a fresh install', function () {
    // A migration lays down a national default rate, so a freshly migrated
    // database is not blank. Cleared here so the assertion is about the
    // checklist rather than about that placeholder.
    DeliveryRate::query()->delete();

    $this->actingAs(dashboardUser('Administrator'))
        ->get(dashboardUrl())
        ->assertInertia(function ($page) {
            $setup = collect($page->toArray()['props']['setup']);

            expect($setup)->toHaveCount(6)
                ->and($setup->every(fn ($step) => $step['done'] === false))->toBeTrue();
        });
});

it('gets out of the way once everything is set up', function () {
    finishSetup();

    // An empty array, not six ticks — a finished checklist is furniture.
    $this->actingAs(dashboardUser('Administrator'))
        ->get(dashboardUrl())
        ->assertInertia(fn ($page) => $page->has('setup', 0));
});

it('ticks off the steps already done', function () {
    DeliveryRate::query()->delete();
    Category::factory()->create();

    $this->actingAs(dashboardUser('Administrator'))
        ->get(dashboardUrl())
        ->assertInertia(function ($page) {
            $setup = collect($page->toArray()['props']['setup']);

            expect($setup->firstWhere('label', 'Add product categories')['done'])->toBeTrue()
                ->and($setup->firstWhere('label', 'Set delivery rates')['done'])->toBeFalse();
        });
});

// ── Figures ─────────────────────────────────────────────────────────────

it('leaves cancelled orders out of the sales figures', function () {
    $product = Product::factory()->approved()->create(['price_kobo' => 50_000_00]);

    Order::factory()->create([
        'vendor_id' => $product->vendor_id,
        'product_id' => $product->id,
        'status' => OrderStatus::Delivered,
        'locked_price_kobo' => 50_000_00,
    ]);
    Order::factory()->create([
        'vendor_id' => $product->vendor_id,
        'product_id' => $product->id,
        'status' => OrderStatus::Cancelled,
        'locked_price_kobo' => 50_000_00,
    ]);

    // Money that was refunded is not money the business took.
    $this->actingAs(dashboardUser('Administrator'))
        ->get(dashboardUrl())
        ->assertInertia(function ($page) {
            $sales = collect($page->toArray()['props']['figures'])->firstWhere('key', 'sales');

            expect($sales['value'])->toBe(50_000_00);
        });
});

it('lists the latest orders newest first', function () {
    $product = Product::factory()->approved()->create();

    $older = Order::factory()->create([
        'vendor_id' => $product->vendor_id,
        'product_id' => $product->id,
    ]);
    $newer = Order::factory()->create([
        'vendor_id' => $product->vendor_id,
        'product_id' => $product->id,
    ]);

    $this->actingAs(dashboardUser('Administrator'))
        ->get(dashboardUrl())
        ->assertInertia(fn ($page) => $page
            ->where('recentOrders.0.uuid', $newer->uuid)
            ->where('recentOrders.1.uuid', $older->uuid));
});

it('renders with nothing in the database at all', function () {
    // The state nobody develops against, and where a ->first()->name
    // assumption shows up as a 500 on the first screen somebody sees.
    $this->actingAs(dashboardUser('Administrator'))
        ->get(dashboardUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('recentOrders', 0));
});
