<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Logistics\Models\CourierProfile;
use App\Modules\Logistics\Models\Shipment;
use App\Modules\Logistics\Services\DeliveryService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\DeliveryPricing;
use App\Modules\Savings\Models\PlanTerm;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Services\SavingsGoalService;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\ShipmentStatus;
use App\Shared\Enums\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

pest()->extend(TestCase::class)->in('Architecture');

/*
|--------------------------------------------------------------------------
| Shared helpers
|--------------------------------------------------------------------------
|
| Orders can only be raised two ways now — a card checkout cleared by the
| Paystack webhook, or a Pay Small Small plan paid off. Most tests just need
| "an order that exists", so these give them one without every file
| rebuilding the plan machinery by hand.
|
*/

/** A term to hang test plans off. */
function testPlanTerm(): PlanTerm
{
    // Two monthly payments. installments is derived from duration_months on
    // save, so the duration is what gets set.
    return PlanTerm::query()->firstOrCreate(
        ['cadence' => PlanCadence::Monthly, 'duration_months' => 2],
        ['name' => 'Test · monthly x2', 'min_target_kobo' => 0, 'is_active' => true],
    );
}

/**
 * A Pay Small Small plan for one unit of $product.
 *
 * @param  array<string, mixed>  $address
 */
function testPlan(User $customer, Product $product, array $address = []): SavingsGoal
{
    return app(SavingsGoalService::class)->createFromLines(
        $customer,
        collect([['cartItemId' => null, 'product' => $product, 'quantity' => 1]]),
        $address + [
            'recipient_name' => 'Yakubu Dauda',
            'recipient_phone' => '08031234567',
            'delivery_address' => '12 Marina Road',
            'state' => 'Lagos',
            'lga' => 'Eti-Osa',
        ],
        testPlanTerm(),
    );
}

/** A plan paid off in full, ready to be collected. */
function testPaidPlan(User $customer, Product $product): SavingsGoal
{
    $plan = testPlan($customer, $product);

    app(SavingsGoalService::class)->recordPayment(
        $customer,
        $plan,
        $plan->target_kobo,
        reference: 'TEST-PAY-'.Str::uuid()->toString(),
    );

    return $plan->refresh();
}

/** One order, the shortest route to an Order in a test. */
function testOrder(User $customer, Product $product): Order
{
    return app(SavingsGoalService::class)
        ->fulfil($customer, testPaidPlan($customer, $product))
        ->first();
}

/**
 * What a plan for $goodsKobo actually locks.
 *
 * Delivery is part of a plan's target, quoted from the same rates a card
 * checkout uses — a basket paid off over six months must not cost less than
 * the same basket paid outright. Tests say goods-plus-delivery rather than a
 * bare figure, so changing the seeded rate cannot silently rewrite what an
 * assertion means.
 */
function planTarget(int $goodsKobo, string $state = 'Lagos'): int
{
    return $goodsKobo + app(DeliveryPricing::class)->feeKobo($goodsKobo, $state);
}

/** A Logistics Personnel account with a courier profile behind it. */
function makeCourier(string $name = 'Musa Ibrahim'): User
{
    $courier = User::factory()->create(['name' => $name, 'user_type' => UserType::Staff]);
    $courier->assignRole('Logistics Personnel');
    CourierProfile::query()->create(['user_id' => $courier->id, 'vehicle_type' => 'motorcycle']);

    return $courier;
}

/**
 * Walk a parcel from wherever it is to $to.
 *
 * Handing over is deliberately not just another step — it needs the code the
 * customer reads out — so this reaches for `deliver()` on the last leg and
 * `advance()` for everything before it. Tests that walked the old chain with
 * one method are the reason the code was easy to skip.
 */
function walkParcel(User $courier, Shipment $shipment, ShipmentStatus $to): Shipment
{
    $delivery = app(DeliveryService::class);

    while ($shipment->status !== $to) {
        $next = $shipment->status->next();

        if ($next === null) {
            break;
        }

        // Read before delivering: handing over spends the code.
        $code = (string) $shipment->delivery_code;

        $shipment = $next === ShipmentStatus::Delivered
            ? $delivery->deliver($courier, $shipment, $code)
            : $delivery->advance($courier, $shipment, $next);

        $shipment = $shipment->fresh();
    }

    return $shipment;
}

/**
 * Post a Paystack webhook with a signature over the exact raw body.
 *
 * Shared rather than living in one test file: the settlement paths that read
 * these events are spread across savings, orders and logistics, and a helper
 * defined in a sibling file only exists when that file happens to be loaded —
 * so running one directory alone made every caller fatal.
 */
/**
 * A URL on the isolated staff subdomain.
 *
 * Shared here rather than inside one test file: it used to be declared in
 * StaffDashboardAccessTest, so every other file that called it only worked
 * when that file happened to be loaded in the same run.
 */
function adminUrl(string $path = ''): string
{
    return 'http://'.config('app.admin_domain').'/'.ltrim($path, '/');
}

/** A URL on the isolated Vendor Center subdomain. Shared for the same reason. */
function vendorUrl(string $path = ''): string
{
    return 'http://'.config('app.vendor_domain').'/'.ltrim($path, '/');
}

function postWebhook(array $payload, ?string $signature = null): TestResponse
{
    $json = json_encode($payload) ?: '';
    $signature ??= hash_hmac('sha512', $json, (string) config('services.paystack.secret_key'));

    return test()->call('POST', '/webhooks/paystack', [], [], [], [
        'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $json);
}

/** @return array<string, mixed> */
function chargeSuccessPayload(string $reference, int $amountKobo): array
{
    return [
        'event' => 'charge.success',
        'data' => [
            'reference' => $reference,
            'amount' => $amountKobo,
            'currency' => 'NGN',
            'channel' => 'card',
            'status' => 'success',
        ],
    ];
}
