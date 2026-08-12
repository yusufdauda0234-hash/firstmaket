<?php

use App\Models\Setting;
use App\Models\User;
use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliateAttribution;
use App\Modules\Affiliates\Models\AffiliateCommission;
use App\Modules\Affiliates\Models\AffiliateConversion;
use App\Modules\Affiliates\Models\AffiliateTier;
use App\Modules\Affiliates\Services\AffiliatePayoutService;
use App\Modules\Affiliates\Services\AffiliateService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Shared\Enums\PayoutItemStatus;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;

/**
 * The partner program's money rules.
 *
 * Every test here is a way a partner could otherwise be paid for work they
 * did not do, or a way somebody else's money could leave wrongly: attribution
 * being stolen or outliving its window, links being hand-edited, conversions
 * paid while still under review, payouts leaving without approval or a
 * verified destination.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->service = app(AffiliateService::class);

    // A test attributes and converts in the same instant, which the velocity
    // heuristic is designed to catch. Switched off here so the money rules
    // can be tested on their own; the heuristic has its own test below that
    // turns it back on.
    Setting::set('affiliates.fraud_min_minutes_to_convert', 0, 'affiliates');
});

function approvedAffiliate(string $name = 'Partner One'): Affiliate
{
    $user = User::factory()->create();
    $admin = User::factory()->create(['user_type' => UserType::Staff]);
    $affiliate = app(AffiliateService::class)->apply($user, $name);
    app(AffiliateService::class)->approve($affiliate, $admin);

    return $affiliate->refresh();
}

function deliveredOrderFor(User $customer, int $priceKobo = 100_000_00): Order
{
    $product = Product::factory()->approved()->create(['price_kobo' => $priceKobo]);

    return Order::factory()->create([
        'customer_id' => $customer->id,
        'product_id' => $product->id,
        'vendor_id' => $product->vendor_id,
        'locked_price_kobo' => $priceKobo,
    ]);
}

// ── Attribution ─────────────────────────────────────────────────────────────

it('keeps the first attribution and never lets a second link overwrite it', function () {
    $first = approvedAffiliate('First');
    $second = approvedAffiliate('Second');
    $customer = User::factory()->create();

    $this->service->attributeSignup($customer, $first->links()->first()->id);
    $this->service->attributeSignup($customer, $second->links()->first()->id);

    $attribution = AffiliateAttribution::query()->where('user_id', $customer->id)->firstOrFail();

    expect(AffiliateAttribution::query()->where('user_id', $customer->id)->count())->toBe(1)
        ->and($attribution->affiliate_link_id)->toBe($first->links()->first()->id);
});

it('stops earning once the attribution window has run out', function () {
    $affiliate = approvedAffiliate();
    $customer = User::factory()->create();
    $this->service->attributeSignup($customer, $affiliate->links()->first()->id);

    // The shopper comes back long after the partner's claim expired.
    AffiliateAttribution::query()->where('user_id', $customer->id)
        ->update(['expires_at' => now()->subDay()]);

    $this->service->qualifyDeliveredOrder(deliveredOrderFor($customer));

    expect(AffiliateConversion::query()
        ->where('affiliate_id', $affiliate->id)
        ->where('conversion_type', AffiliateConversion::TYPE_DELIVERED_ORDER)
        ->exists())->toBeFalse();
});

it('stamps the window in force at the time, so shortening the setting later cannot void it', function () {
    Setting::set('affiliates.attribution_window_days', 120, 'affiliates');
    $affiliate = approvedAffiliate();
    $customer = User::factory()->create();

    $this->service->attributeSignup($customer, $affiliate->links()->first()->id);
    $expiry = AffiliateAttribution::query()->where('user_id', $customer->id)->value('expires_at');

    Setting::set('affiliates.attribution_window_days', 7, 'affiliates');
    Setting::flushCache();

    expect(now()->diffInDays($expiry))->toBeGreaterThan(100);
});

it('refuses to attribute an affiliate to their own account', function () {
    $affiliate = approvedAffiliate();

    $this->service->attributeSignup($affiliate->user, $affiliate->links()->first()->id);

    expect(AffiliateAttribution::query()->where('user_id', $affiliate->user_id)->exists())->toBeFalse();
});

// ── Signed links ────────────────────────────────────────────────────────────

it('refuses a link whose signature has been tampered with', function () {
    $affiliate = approvedAffiliate();
    $link = $affiliate->links()->first();

    expect($this->service->capture($link->code, '127.0.0.1', 'agent', $link->signature))->not->toBeNull()
        ->and($this->service->capture($link->code, '127.0.0.1', 'agent', 'not-the-real-signature'))->toBeNull()
        ->and($this->service->capture($link->code, '127.0.0.1', 'agent', null))->toBeNull();
});

it('never forwards a partner link to a destination taken from the URL', function () {
    $affiliate = approvedAffiliate();
    $link = $affiliate->links()->first();

    // An open redirect on the marketplace's own domain is exactly what a
    // phishing campaign wants; the capture route must always land at home.
    $this->get(route('affiliates.capture', ['code' => $link->code, 's' => $link->signature, 'next' => 'https://evil.example']))
        ->assertRedirectContains(route('home'));
});

it('stops capturing through an expired or switched-off link', function () {
    $affiliate = approvedAffiliate();
    $link = $affiliate->links()->first();
    $link->forceFill(['expires_at' => now()->subMinute()])->save();

    expect($this->service->capture($link->code, '127.0.0.1', 'agent', $link->signature))->toBeNull();
});

// ── Suspension ──────────────────────────────────────────────────────────────

it('stops a suspended affiliate earning on a delivery that lands after the suspension', function () {
    $affiliate = approvedAffiliate();
    $customer = User::factory()->create();
    $this->service->attributeSignup($customer, $affiliate->links()->first()->id);

    $this->service->suspend($affiliate, 'Traffic under investigation.');

    $this->service->qualifyDeliveredOrder(deliveredOrderFor($customer));

    expect(AffiliateConversion::query()
        ->where('affiliate_id', $affiliate->id)
        ->where('conversion_type', AffiliateConversion::TYPE_DELIVERED_ORDER)
        ->exists())->toBeFalse();
});

it('lets a suspended affiliate keep what they already earned', function () {
    $affiliate = approvedAffiliate();
    $customer = User::factory()->create();
    $this->service->attributeSignup($customer, $affiliate->links()->first()->id);
    $this->service->qualifyDeliveredOrder(deliveredOrderFor($customer));

    $earnedBefore = (int) $affiliate->commissions()->sum('amount_kobo');
    $this->service->suspend($affiliate, 'Under review.');

    expect($earnedBefore)->toBeGreaterThan(0)
        ->and((int) $affiliate->fresh()->commissions()->sum('amount_kobo'))->toBe($earnedBefore);
});

it('refuses to create a new link while suspended', function () {
    $affiliate = approvedAffiliate();
    $this->service->suspend($affiliate, 'Under review.');

    expect(fn () => $this->service->createLink($affiliate->fresh(), 'Sneaky', null))
        ->toThrow(ValidationException::class);
});

// ── Commission tiers ────────────────────────────────────────────────────────

it('pays the rate of the rank the partner is on, and does not promote them for selling', function () {
    AffiliateTier::query()->create([
        'name' => 'Base', 'commission_percent' => 5, 'is_default' => true, 'referral_quota' => 0,
        'min_delivered_conversions' => 0, 'min_delivered_value_kobo' => 0, 'sort_order' => 1,
    ]);
    $top = AffiliateTier::query()->create([
        'name' => 'Top', 'commission_percent' => 10, 'is_default' => false, 'referral_quota' => 0,
        'min_delivered_conversions' => 1, 'min_delivered_value_kobo' => 0, 'sort_order' => 2,
    ]);

    $affiliate = approvedAffiliate();
    $first = User::factory()->create();
    $this->service->attributeSignup($first, $affiliate->links()->first()->id);
    $this->service->qualifyDeliveredOrder(deliveredOrderFor($first, 100_000_00));

    expect((int) $affiliate->commissions()->sum('amount_kobo'))->toBe(500_000);

    $second = User::factory()->create();
    $this->service->attributeSignup($second, $affiliate->links()->first()->id);
    $this->service->qualifyDeliveredOrder(deliveredOrderFor($second, 100_000_00));

    /*
     * Still the base rate. Ranks used to promote silently the moment a
     * threshold was crossed; since a rank now also widens the referral quota
     * and the link lifetime, it is granted by review instead. Crossing the
     * threshold means "you may apply", not "you have been moved".
     */
    expect((int) $affiliate->fresh()->commissions()->sum('amount_kobo'))->toBe(1_000_000)
        ->and($affiliate->fresh()->tier?->name)->not->toBe('Top');

    // Granted, the new rate applies from the next sale.
    app(\App\Modules\Affiliates\Services\AffiliateRankService::class)->assignRank($affiliate->fresh(), $top);

    $third = User::factory()->create();
    $this->service->attributeSignup($third, $affiliate->links()->first()->id);
    $this->service->qualifyDeliveredOrder(deliveredOrderFor($third, 100_000_00));

    expect((int) $affiliate->fresh()->commissions()->sum('amount_kobo'))->toBe(1_000_000 + 1_000_000);
});

it('does not pay for a bare signup', function () {
    $affiliate = approvedAffiliate();
    $customer = User::factory()->create();

    $this->service->attributeSignup($customer, $affiliate->links()->first()->id);

    expect(AffiliateConversion::query()->where('conversion_type', AffiliateConversion::TYPE_SIGNUP)->count())->toBe(1)
        ->and((int) $affiliate->commissions()->sum('amount_kobo'))->toBe(0);
});

it('records a plan-funded order as its own conversion type', function () {
    $affiliate = approvedAffiliate();
    $customer = User::factory()->create();
    $this->service->attributeSignup($customer, $affiliate->links()->first()->id);

    $order = deliveredOrderFor($customer);
    $goal = \App\Modules\Savings\Models\SavingsGoal::query()->create([
        'user_id' => $customer->id, 'target_kobo' => 100_000_00, 'delivery_fee_kobo' => 0,
        'installments' => 3, 'installment_kobo' => 33_333_00, 'paid_kobo' => 100_000_00,
        'status' => \App\Shared\Enums\SavingsGoalStatus::Fulfilled,
    ]);
    $order->forceFill(['savings_goal_id' => $goal->id])->save();

    $this->service->qualifyDeliveredOrder($order->fresh());

    expect(AffiliateConversion::query()
        ->where('conversion_type', AffiliateConversion::TYPE_COMPLETED_PLAN_ORDER)
        ->exists())->toBeTrue();
});

// ── Fraud heuristics ────────────────────────────────────────────────────────

it('holds a conversion for review, and writes no commission, when it converts too fast to be organic', function () {
    Setting::set('affiliates.fraud_min_minutes_to_convert', 30, 'affiliates');
    Setting::flushCache();

    $affiliate = approvedAffiliate();
    $customer = User::factory()->create();
    $this->service->attributeSignup($customer, $affiliate->links()->first()->id);
    $this->service->qualifyDeliveredOrder(deliveredOrderFor($customer));

    $conversion = AffiliateConversion::query()
        ->where('conversion_type', AffiliateConversion::TYPE_DELIVERED_ORDER)
        ->firstOrFail();

    expect($conversion->status)->toBe(AffiliateConversion::STATUS_REVIEW)
        ->and($conversion->fraudFlags()->count())->toBe(1)
        // Under review earns nothing: a commission row is a commission
        // somebody expects to be paid.
        ->and((int) $affiliate->commissions()->sum('amount_kobo'))->toBe(0);
});

it('writes the commission only once a reviewer clears the flagged conversion', function () {
    Setting::set('affiliates.fraud_min_minutes_to_convert', 30, 'affiliates');
    Setting::flushCache();

    $affiliate = approvedAffiliate();
    $customer = User::factory()->create();
    $this->service->attributeSignup($customer, $affiliate->links()->first()->id);
    $this->service->qualifyDeliveredOrder(deliveredOrderFor($customer, 100_000_00));

    $conversion = AffiliateConversion::query()
        ->where('conversion_type', AffiliateConversion::TYPE_DELIVERED_ORDER)
        ->firstOrFail();

    $reviewer = User::factory()->create(['user_type' => UserType::Staff]);
    $reviewer->forceFill(['two_factor_confirmed_at' => now()])->save();
    $reviewer->assignRole('Administrator');
    $reviewer->givePermissionTo('affiliate_conversions.review');

    $this->actingAs($reviewer)
        ->post(adminUrl("/affiliates/conversions/{$conversion->id}/approve"))
        ->assertRedirect();

    expect($conversion->fresh()->status)->toBe(AffiliateConversion::STATUS_QUALIFIED)
        ->and((int) $affiliate->fresh()->commissions()->sum('amount_kobo'))->toBeGreaterThan(0);
});

// ── Payouts ─────────────────────────────────────────────────────────────────

function financeUser(): User
{
    $user = User::factory()->create(['user_type' => UserType::Staff]);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    $user->assignRole('Finance Officer');

    return $user;
}

function earnCommission(Affiliate $affiliate, int $amountKobo): AffiliateCommission
{
    $customer = User::factory()->create();
    $conversion = AffiliateConversion::query()->create([
        'affiliate_id' => $affiliate->id,
        'user_id' => $customer->id,
        'conversion_type' => AffiliateConversion::TYPE_DELIVERED_ORDER,
        'status' => AffiliateConversion::STATUS_QUALIFIED,
        'order_value_kobo' => $amountKobo * 20,
        'qualified_at' => now(),
    ]);

    return AffiliateCommission::query()->create([
        'affiliate_id' => $affiliate->id,
        'conversion_id' => $conversion->id,
        'amount_kobo' => $amountKobo,
        'status' => AffiliateCommission::STATUS_PENDING,
    ]);
}

function verifiedAccountFor(Affiliate $affiliate): void
{
    $payouts = app(AffiliatePayoutService::class);
    $account = $payouts->addBankAccount($affiliate, [
        'bank_name' => 'Test Bank', 'account_number' => '0123456789', 'account_name' => 'Partner One',
    ]);
    $payouts->verifyBankAccount(financeUser(), $account);
}

it('leaves a partner under the minimum threshold out of the batch', function () {
    Setting::set('affiliates.payout_minimum_kobo', 500_000, 'affiliates');
    $affiliate = approvedAffiliate();
    verifiedAccountFor($affiliate);
    earnCommission($affiliate, 100_000); // ₦1,000 — under ₦5,000

    $batch = app(AffiliatePayoutService::class)->generateBatch(financeUser());

    expect($batch->items()->count())->toBe(0)
        ->and($batch->total_amount_kobo)->toBe(0);
});

it('leaves a partner with no verified account out of the batch', function () {
    Setting::set('affiliates.payout_minimum_kobo', 100_000, 'affiliates');
    $affiliate = approvedAffiliate();
    // An account exists but nobody has verified it.
    app(AffiliatePayoutService::class)->addBankAccount($affiliate, [
        'bank_name' => 'Test Bank', 'account_number' => '0123456789', 'account_name' => 'Partner One',
    ]);
    earnCommission($affiliate, 900_000);

    expect(app(AffiliatePayoutService::class)->generateBatch(financeUser())->items()->count())->toBe(0);
});

it('never gathers a commission whose conversion is still in review or rejected', function () {
    Setting::set('affiliates.payout_minimum_kobo', 0, 'affiliates');
    $affiliate = approvedAffiliate();
    verifiedAccountFor($affiliate);

    $payable = earnCommission($affiliate, 800_000);
    $underReview = earnCommission($affiliate, 900_000);
    $underReview->conversion->forceFill(['status' => AffiliateConversion::STATUS_REVIEW])->save();
    $rejected = earnCommission($affiliate, 700_000);
    $rejected->conversion->forceFill(['status' => AffiliateConversion::STATUS_REJECTED])->save();

    $batch = app(AffiliatePayoutService::class)->generateBatch(financeUser());

    expect($batch->total_amount_kobo)->toBe(800_000)
        ->and($payable->fresh()->status)->toBe(AffiliateCommission::STATUS_BATCHED)
        ->and($underReview->fresh()->status)->toBe(AffiliateCommission::STATUS_PENDING);
});

it('cannot mark a payout paid before the batch is approved', function () {
    Setting::set('affiliates.payout_minimum_kobo', 0, 'affiliates');
    $affiliate = approvedAffiliate();
    verifiedAccountFor($affiliate);
    earnCommission($affiliate, 800_000);

    $payouts = app(AffiliatePayoutService::class);
    $batch = $payouts->generateBatch(financeUser());

    expect(fn () => $payouts->markItemPaid(financeUser(), $batch->items()->first(), 'TRF_1'))
        ->toThrow(ValidationException::class);
});

it('marks commissions paid once the transfer is recorded', function () {
    Setting::set('affiliates.payout_minimum_kobo', 0, 'affiliates');
    $affiliate = approvedAffiliate();
    verifiedAccountFor($affiliate);
    $commission = earnCommission($affiliate, 800_000);

    $payouts = app(AffiliatePayoutService::class);
    $finance = financeUser();
    $batch = $payouts->generateBatch($finance);
    $payouts->approveBatch($finance, $batch);
    $payouts->markItemPaid($finance, $batch->items()->first(), 'TRF_1');

    expect($commission->fresh()->status)->toBe(AffiliateCommission::STATUS_PAID)
        ->and($batch->fresh()->items()->first()->status)->toBe(PayoutItemStatus::Paid);
});

it('returns commissions to pending when a payout line is rejected, so they roll into next month', function () {
    Setting::set('affiliates.payout_minimum_kobo', 0, 'affiliates');
    $affiliate = approvedAffiliate();
    verifiedAccountFor($affiliate);
    $commission = earnCommission($affiliate, 800_000);

    $payouts = app(AffiliatePayoutService::class);
    $finance = financeUser();
    $batch = $payouts->generateBatch($finance);
    $payouts->rejectItem($finance, $batch->items()->first(), 'Account name does not match.');

    expect($commission->fresh()->status)->toBe(AffiliateCommission::STATUS_PENDING)
        ->and($commission->fresh()->payout_item_id)->toBeNull();
});

it('voids the commission when a conversion is rejected in review', function () {
    $affiliate = approvedAffiliate();
    $commission = earnCommission($affiliate, 800_000);

    $this->service->rejectConversion($commission->conversion, 'Self-referral confirmed.');

    expect($commission->fresh()->status)->toBe(AffiliateCommission::STATUS_VOID);
});

it('excludes a suspended partner from a payout batch entirely', function () {
    Setting::set('affiliates.payout_minimum_kobo', 0, 'affiliates');
    $affiliate = approvedAffiliate();
    verifiedAccountFor($affiliate);
    earnCommission($affiliate, 800_000);
    $this->service->suspend($affiliate, 'Under investigation.');

    expect(app(AffiliatePayoutService::class)->generateBatch(financeUser())->items()->count())->toBe(0);
});

// ── Authorisation ───────────────────────────────────────────────────────────

it('keeps the payout screen away from staff who may only review affiliates', function () {
    $reviewer = User::factory()->create(['user_type' => UserType::Staff]);
    $reviewer->forceFill(['two_factor_confirmed_at' => now()])->save();
    $reviewer->assignRole('Support Agent');
    $reviewer->givePermissionTo('affiliates.manage');

    $this->actingAs($reviewer)
        ->get(adminUrl('/affiliates/payouts'))
        ->assertForbidden();
});

it('never exposes a referred customer\'s identity on the partner dashboard', function () {
    $affiliate = approvedAffiliate();
    $customer = User::factory()->create(['name' => 'Amaka Referred', 'email' => 'amaka@example.test']);
    $this->service->attributeSignup($customer, $affiliate->links()->first()->id);
    $this->service->qualifyDeliveredOrder(deliveredOrderFor($customer));

    $response = $this->actingAs($affiliate->user)->get(route('affiliates.index'));

    $response->assertOk();
    expect($response->getContent())
        ->not->toContain('Amaka Referred')
        ->and($response->getContent())->not->toContain('amaka@example.test');
});
