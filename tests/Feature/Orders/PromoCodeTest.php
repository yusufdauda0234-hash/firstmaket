<?php

use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\PromoCode;
use App\Modules\Orders\Models\PromoRedemption;
use App\Modules\Orders\Services\PromoRedeemer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Promo codes: what they take off, who may use them, and how a basket
 * discount is split across the one-row-per-unit orders it pays for.
 *
 * Discounts are platform-funded — they come out of commission, never out of
 * the vendor's earning.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->customer = User::factory()->create(['phone_verified_at' => now()]);
    $this->customer->assignRole('Customer');
    CustomerProfile::query()->create(['user_id' => $this->customer->id]);

    $this->redeemer = app(PromoRedeemer::class);
});

function promo(array $attributes = []): PromoCode
{
    return PromoCode::query()->create(array_merge([
        'code' => 'SAVE10',
        'type' => 'percent',
        'percent_off' => '10.00',
        'max_discount_kobo' => 10_000_00,
        'is_active' => true,
    ], $attributes));
}

/** A redemption needs a real checkout to hang off — the FK is enforced. */
function promoCheckout(User $customer): CheckoutSession
{
    return CheckoutSession::query()->create([
        'user_id' => $customer->id,
        'total_amount_kobo' => 50_000_00,
        'delivery_address' => '12 Marina Road',
        'state' => 'Lagos',
        'lga' => 'Eti-Osa',
        'status' => 'paid',
    ]);
}

// ── What a code takes off ───────────────────────────────────────────────

it('takes a percentage off the basket', function () {
    expect(promo()->discountOn(50_000_00))->toBe(5_000_00);
});

it('caps a percentage code at its ceiling', function () {
    // 10% of ₦2m is ₦200,000; the cap holds it to ₦10,000.
    expect(promo()->discountOn(2_000_000_00))->toBe(10_000_00);
});

it('takes a fixed amount off', function () {
    $code = promo(['code' => 'FLAT500', 'type' => 'fixed', 'percent_off' => null, 'amount_off_kobo' => 500_00]);

    expect($code->discountOn(50_000_00))->toBe(500_00);
});

it('never discounts more than the basket is worth', function () {
    $code = promo(['code' => 'HUGE', 'type' => 'fixed', 'percent_off' => null, 'amount_off_kobo' => 90_000_00]);

    expect($code->discountOn(1_000_00))->toBe(1_000_00);
});

it('discounts delivery rather than goods on a free-delivery code', function () {
    $code = promo(['code' => 'FREESHIP', 'type' => 'free_delivery', 'percent_off' => null]);

    expect($code->discountOn(50_000_00, 1_500_00))->toBe(1_500_00);
});

// ── Who may use it ──────────────────────────────────────────────────────

it('matches a code whatever case it is typed in', function () {
    promo();

    expect($this->redeemer->quote($this->customer, 'save10', 50_000_00)['discountKobo'])->toBe(5_000_00);
});

it('refuses a code that does not exist', function () {
    $this->redeemer->quote($this->customer, 'NOPE', 50_000_00);
})->throws(ValidationException::class);

it('refuses a switched-off code', function () {
    promo(['is_active' => false]);

    $this->redeemer->quote($this->customer, 'SAVE10', 50_000_00);
})->throws(ValidationException::class);

it('refuses a code that has expired', function () {
    promo(['ends_at' => now()->subDay()]);

    $this->redeemer->quote($this->customer, 'SAVE10', 50_000_00);
})->throws(ValidationException::class);

it('refuses a code that has not started', function () {
    promo(['starts_at' => now()->addDay()]);

    $this->redeemer->quote($this->customer, 'SAVE10', 50_000_00);
})->throws(ValidationException::class);

it('refuses a basket below the minimum', function () {
    promo(['min_order_kobo' => 20_000_00]);

    $this->redeemer->quote($this->customer, 'SAVE10', 5_000_00);
})->throws(ValidationException::class);

it('refuses a second use by the same customer', function () {
    $code = promo();
    $this->redeemer->redeem($this->customer, $code, promoCheckout($this->customer)->id, 5_000_00);

    $this->redeemer->quote($this->customer, 'SAVE10', 50_000_00);
})->throws(ValidationException::class);

it('refuses a first-order code once the customer has ordered', function () {
    promo(['first_order_only' => true]);
    $product = Product::factory()->approved()->create();
    Order::factory()->create([
        'customer_id' => $this->customer->id,
        'vendor_id' => $product->vendor_id,
        'product_id' => $product->id,
    ]);

    $this->redeemer->quote($this->customer, 'SAVE10', 50_000_00);
})->throws(ValidationException::class);

// ── The guard that keeps a promotion from becoming a loss ───────────────

it('caps the discount at the commission funding it', function () {
    promo();

    // 10% of ₦50,000 is ₦5,000, but only ₦3,000 of commission is available;
    // beyond that FirstMaket pays out of the vendor's earning.
    expect($this->redeemer->quote($this->customer, 'SAVE10', 50_000_00, 0, 3_000_00)['discountKobo'])
        ->toBe(3_000_00)
        ->and($this->redeemer->quote($this->customer, 'SAVE10', 50_000_00, 0, 9_000_00)['discountKobo'])
        ->toBe(5_000_00);
});

// ── Spending it ─────────────────────────────────────────────────────────

it('is idempotent for one checkout', function () {
    $code = promo();
    $session = promoCheckout($this->customer);

    $first = $this->redeemer->redeem($this->customer, $code, $session->id, 5_000_00);
    $second = $this->redeemer->redeem($this->customer, $code, $session->id, 5_000_00);

    expect($second->id)->toBe($first->id)
        ->and(PromoRedemption::query()->count())->toBe(1);
});

it('refuses to spend a code that is fully claimed', function () {
    $code = promo(['max_redemptions' => 1]);
    $other = User::factory()->create();
    $this->redeemer->redeem($other, $code, promoCheckout($other)->id, 5_000_00);

    $this->redeemer->redeem($this->customer, $code, promoCheckout($this->customer)->id, 5_000_00);
})->throws(ValidationException::class);

it('gives a use back when an order is refunded', function () {
    $code = promo();
    $session = promoCheckout($this->customer);
    $this->redeemer->redeem($this->customer, $code, $session->id, 5_000_00);

    expect($this->redeemer->release($session->id))->toBe(1);

    // Released, so the customer may use it again.
    expect($this->redeemer->quote($this->customer, 'SAVE10', 50_000_00)['discountKobo'])->toBe(5_000_00);
});

it('does not release the same redemption twice', function () {
    $code = promo();
    $session = promoCheckout($this->customer);
    $this->redeemer->redeem($this->customer, $code, $session->id, 5_000_00);

    $this->redeemer->release($session->id);

    expect($this->redeemer->release($session->id))->toBe(0);
});

// ── Apportionment: the rounding trap ────────────────────────────────────

it('splits a discount so the parts sum to exactly the whole', function () {
    // ₦5,000 over three equal units is 1666.666… each.
    $shares = $this->redeemer->apportion(5_000_00, [10_000_00, 10_000_00, 10_000_00]);

    expect(array_sum($shares))->toBe(5_000_00)
        ->and($shares)->toHaveCount(3);
});

it('splits in proportion to each unit price', function () {
    $shares = $this->redeemer->apportion(3_000_00, [10_000_00, 20_000_00]);

    expect($shares[0])->toBe(1_000_00)
        ->and($shares[1])->toBe(2_000_00);
});

it('keeps every share within a kobo of its fair value', function () {
    $shares = $this->redeemer->apportion(50_000, [33_333, 33_333, 33_334]);

    expect(array_sum($shares))->toBe(50_000);

    foreach ($shares as $key => $share) {
        $fair = 50_000 * [33_333, 33_333, 33_334][$key] / 100_000;
        expect(abs($share - $fair))->toBeLessThan(1.0);
    }
});

it('never apportions more than the units are worth', function () {
    $shares = $this->redeemer->apportion(90_000_00, [1_000_00, 1_000_00]);

    expect(array_sum($shares))->toBe(2_000_00);
});

it('apportions nothing across an empty basket', function () {
    expect($this->redeemer->apportion(5_000_00, []))->toBe([]);
});

it('keeps the caller keys so a share can be matched back to its order', function () {
    $shares = $this->redeemer->apportion(3_000_00, ['a' => 10_000_00, 'b' => 20_000_00]);

    expect(array_keys($shares))->toEqualCanonicalizing(['a', 'b']);
});
