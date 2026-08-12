<?php

use App\Models\Setting;
use App\Models\User;
use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliateCommission;
use App\Modules\Affiliates\Models\AffiliateConversion;
use App\Modules\Affiliates\Models\AffiliateRankRequirement;
use App\Modules\Affiliates\Models\AffiliateTier;
use App\Modules\Affiliates\Models\AffiliateUpgradeRequest;
use App\Modules\Affiliates\Services\AffiliateRankService;
use App\Modules\Affiliates\Services\AffiliateService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;

/**
 * The rank ladder.
 *
 * A rank decides three things at once: how many referrals a partner may earn
 * on before somebody looks at them again, how long their links live, and what
 * they are paid. The quota is the interesting one — it is the point at which
 * the programme stops paying out to somebody nobody has verified.
 *
 * The rule that shapes most of these tests: running out of quota pauses
 * *earning*, never the customer's experience. Somebody who clicks a link must
 * still be able to sign up and shop.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->ranks = app(AffiliateRankService::class);
    $this->affiliates = app(AffiliateService::class);

    // The velocity heuristic would otherwise flag every conversion here, since
    // a test attributes and converts in the same instant.
    Setting::set('affiliates.fraud_min_minutes_to_convert', 0, 'affiliates');
});

function rank(string $name, int $order, array $attributes = []): AffiliateTier
{
    return AffiliateTier::query()->create(array_merge([
        'name' => $name,
        'commission_percent' => 5,
        'referral_quota' => 3,
        'link_expiry_days' => 30,
        'max_active_links' => 1,
        'requires_approval' => true,
        'is_default' => $order === 1,
        'is_active' => true,
        'sort_order' => $order,
    ], $attributes));
}

function rankedAffiliate(?AffiliateTier $tier = null): Affiliate
{
    $user = User::factory()->create();
    $admin = User::factory()->create(['user_type' => UserType::Staff]);
    $affiliate = app(AffiliateService::class)->apply($user, 'Partner');
    app(AffiliateService::class)->approve($affiliate, $admin);
    $affiliate->refresh();

    if ($tier !== null) {
        app(AffiliateRankService::class)->assignRank($affiliate, $tier);
        $affiliate->refresh();
    }

    return $affiliate;
}

function referAndDeliver(Affiliate $affiliate, int $priceKobo = 100_000_00): Order
{
    $customer = User::factory()->create();
    app(AffiliateService::class)->attributeSignup($customer, $affiliate->links()->first()->id);

    $product = Product::factory()->approved()->create(['price_kobo' => $priceKobo]);
    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'product_id' => $product->id,
        'vendor_id' => $product->vendor_id,
        'locked_price_kobo' => $priceKobo,
    ]);

    app(AffiliateService::class)->qualifyDeliveredOrder($order);

    return $order;
}

// ── The quota ───────────────────────────────────────────────────────────────

it('pays for referrals up to the rank quota and no further', function () {
    $starter = rank('Starter', 1, ['referral_quota' => 3, 'commission_percent' => 10]);
    $affiliate = rankedAffiliate($starter);

    for ($i = 0; $i < 4; $i++) {
        referAndDeliver($affiliate, 100_000_00);
    }

    // Four people were referred; three were paid for.
    expect($affiliate->conversions()->where('conversion_type', AffiliateConversion::TYPE_DELIVERED_ORDER)->count())->toBe(4)
        ->and(AffiliateCommission::query()->where('affiliate_id', $affiliate->id)->count())->toBe(3);
});

it('still lets a referred customer sign up after the quota is spent', function () {
    $starter = rank('Starter', 1, ['referral_quota' => 1]);
    $affiliate = rankedAffiliate($starter);
    referAndDeliver($affiliate);

    // The link is not switched off — dropping a shopper on a dead page to
    // punish the partner would cost a sale to make a point.
    $latecomer = User::factory()->create();
    $this->affiliates->attributeSignup($latecomer, $affiliate->links()->first()->id);

    expect(\App\Modules\Affiliates\Models\AffiliateAttribution::query()
        ->where('user_id', $latecomer->id)->exists())->toBeTrue();
});

it('still records the referral so the partner can see it happened', function () {
    $starter = rank('Starter', 1, ['referral_quota' => 1]);
    $affiliate = rankedAffiliate($starter);

    referAndDeliver($affiliate);
    referAndDeliver($affiliate);

    expect($this->ranks->referralsUsed($affiliate))->toBe(2)
        ->and($this->ranks->canEarn($affiliate))->toBeFalse();
});

it('reports what is left on the rank', function () {
    $starter = rank('Starter', 1, ['referral_quota' => 3]);
    $affiliate = rankedAffiliate($starter);

    expect($this->ranks->referralsRemaining($affiliate))->toBe(3);

    referAndDeliver($affiliate);

    expect($this->ranks->referralsRemaining($affiliate))->toBe(2);
});

it('never caps a rank whose quota is unlimited', function () {
    $top = rank('Partner', 1, ['referral_quota' => 0]);
    $affiliate = rankedAffiliate($top);

    for ($i = 0; $i < 5; $i++) {
        referAndDeliver($affiliate);
    }

    expect($this->ranks->referralsRemaining($affiliate))->toBeNull()
        ->and(AffiliateCommission::query()->where('affiliate_id', $affiliate->id)->count())->toBe(5);
});

it('starts the allowance again when a partner moves up a rank', function () {
    $starter = rank('Starter', 1, ['referral_quota' => 1]);
    $growth = rank('Growth', 2, ['referral_quota' => 5, 'is_default' => false]);
    $affiliate = rankedAffiliate($starter);

    referAndDeliver($affiliate);
    expect($this->ranks->canEarn($affiliate))->toBeFalse();

    $this->ranks->assignRank($affiliate, $growth);

    // The referrals used on the old rank do not follow them up the ladder.
    expect($this->ranks->referralsUsed($affiliate->fresh()))->toBe(0)
        ->and($this->ranks->canEarn($affiliate->fresh()))->toBeTrue();
});

// ── Links ───────────────────────────────────────────────────────────────────

it('expires a new link according to the rank that created it', function () {
    $starter = rank('Starter', 1, ['link_expiry_days' => 30, 'max_active_links' => 0]);
    $affiliate = rankedAffiliate($starter);

    $link = $this->affiliates->createLink($affiliate, 'Instagram', null);

    expect($link->expires_at)->not->toBeNull()
        ->and((int) round(now()->diffInDays($link->expires_at)))->toBe(30);
});

it('never lets a partner ask for a longer link than their rank allows', function () {
    $starter = rank('Starter', 1, ['link_expiry_days' => 7, 'max_active_links' => 0]);
    $affiliate = rankedAffiliate($starter);

    $link = $this->affiliates->createLink($affiliate, 'Too long', null, now()->addYear());

    expect((int) round(now()->diffInDays($link->expires_at)))->toBe(7);
});

it('lets links live forever on a rank that says so', function () {
    $top = rank('Partner', 1, ['link_expiry_days' => 0, 'max_active_links' => 0]);
    $affiliate = rankedAffiliate($top);

    expect($this->affiliates->createLink($affiliate, 'Forever', null)->expires_at)->toBeNull();
});

it('holds a partner to the number of live links their rank allows', function () {
    $starter = rank('Starter', 1, ['max_active_links' => 2]);
    $affiliate = rankedAffiliate($starter);

    // approve() already made one.
    $this->affiliates->createLink($affiliate, 'Second', null);

    expect(fn () => $this->affiliates->createLink($affiliate, 'Third', null))
        ->toThrow(ValidationException::class);
});

// ── Upgrading ───────────────────────────────────────────────────────────────

it('refuses an application that is missing something the rank asks for', function () {
    $starter = rank('Starter', 1);
    $growth = rank('Growth', 2, ['is_default' => false]);
    AffiliateRankRequirement::query()->create([
        'tier_id' => $growth->id, 'label' => 'CAC document',
        'type' => AffiliateRankRequirement::TYPE_DOCUMENT, 'is_required' => true,
    ]);

    $affiliate = rankedAffiliate($starter);

    expect(fn () => $this->ranks->requestUpgrade($affiliate, []))
        ->toThrow(ValidationException::class);
});

it('accepts an application once everything required is answered', function () {
    $starter = rank('Starter', 1);
    $growth = rank('Growth', 2, ['is_default' => false]);
    $requirement = AffiliateRankRequirement::query()->create([
        'tier_id' => $growth->id, 'label' => 'Brand name',
        'type' => AffiliateRankRequirement::TYPE_TEXT, 'is_required' => true,
    ]);

    $affiliate = rankedAffiliate($starter);

    $request = $this->ranks->requestUpgrade($affiliate, [
        $requirement->id => ['value' => 'Naija Finds'],
    ]);

    expect($request->status)->toBe(AffiliateUpgradeRequest::STATUS_PENDING)
        ->and($request->to_tier_id)->toBe($growth->id)
        ->and($request->answers()->count())->toBe(1);
});

it('does not move anybody up until a human approves it', function () {
    $starter = rank('Starter', 1);
    $growth = rank('Growth', 2, ['is_default' => false]);
    $affiliate = rankedAffiliate($starter);

    $request = $this->ranks->requestUpgrade($affiliate, []);

    // Applying changes nothing on its own.
    expect($affiliate->fresh()->tier_id)->toBe($starter->id);

    $staff = User::factory()->create(['user_type' => UserType::Staff]);
    $this->ranks->approveUpgrade($staff, $request);

    expect($affiliate->fresh()->tier_id)->toBe($growth->id);
});

it('leaves the partner where they are when an application is rejected', function () {
    $starter = rank('Starter', 1);
    rank('Growth', 2, ['is_default' => false]);
    $affiliate = rankedAffiliate($starter);
    $request = $this->ranks->requestUpgrade($affiliate, []);

    $staff = User::factory()->create(['user_type' => UserType::Staff]);
    $this->ranks->rejectUpgrade($staff, $request, 'The document was unreadable.');

    expect($affiliate->fresh()->tier_id)->toBe($starter->id)
        ->and($request->fresh()->rejection_reason)->toBe('The document was unreadable.');
});

it('refuses a second application while one is still waiting', function () {
    $starter = rank('Starter', 1);
    rank('Growth', 2, ['is_default' => false]);
    $affiliate = rankedAffiliate($starter);

    $this->ranks->requestUpgrade($affiliate, []);

    expect(fn () => $this->ranks->requestUpgrade($affiliate->fresh(), []))
        ->toThrow(ValidationException::class);
});

it('refuses to decide the same application twice', function () {
    $starter = rank('Starter', 1);
    rank('Growth', 2, ['is_default' => false]);
    $affiliate = rankedAffiliate($starter);
    $request = $this->ranks->requestUpgrade($affiliate, []);

    $staff = User::factory()->create(['user_type' => UserType::Staff]);
    $this->ranks->approveUpgrade($staff, $request);

    expect(fn () => $this->ranks->approveUpgrade($staff, $request->fresh()))
        ->toThrow(ValidationException::class);
});

it('has nowhere to send somebody already at the top', function () {
    $top = rank('Partner', 1, ['referral_quota' => 0]);
    $affiliate = rankedAffiliate($top);

    expect($this->ranks->nextRankFor($affiliate))->toBeNull()
        ->and(fn () => $this->ranks->requestUpgrade($affiliate, []))->toThrow(ValidationException::class);
});

it('refuses an application from a suspended partner', function () {
    $starter = rank('Starter', 1);
    rank('Growth', 2, ['is_default' => false]);
    $affiliate = rankedAffiliate($starter);
    $this->affiliates->suspend($affiliate, 'Under investigation.');

    expect(fn () => $this->ranks->requestUpgrade($affiliate->fresh(), []))
        ->toThrow(ValidationException::class);
});

// ── The rate follows the rank ───────────────────────────────────────────────

it('pays the rate of the rank the partner is actually on', function () {
    $starter = rank('Starter', 1, ['commission_percent' => 5, 'referral_quota' => 0]);
    $growth = rank('Growth', 2, ['commission_percent' => 10, 'referral_quota' => 0, 'is_default' => false]);

    $affiliate = rankedAffiliate($starter);
    referAndDeliver($affiliate, 100_000_00);

    expect((int) $affiliate->commissions()->sum('amount_kobo'))->toBe(500_000);

    // Being promoted changes what the next sale is worth, not the last one.
    $this->ranks->assignRank($affiliate, $growth);
    referAndDeliver($affiliate->fresh(), 100_000_00);

    expect((int) $affiliate->fresh()->commissions()->sum('amount_kobo'))->toBe(500_000 + 1_000_000);
});

it('does not promote anybody on its own, however much they sell', function () {
    $starter = rank('Starter', 1, ['referral_quota' => 0, 'min_delivered_conversions' => 0]);
    rank('Growth', 2, ['referral_quota' => 0, 'min_delivered_conversions' => 1, 'is_default' => false]);

    $affiliate = rankedAffiliate($starter);
    referAndDeliver($affiliate);
    referAndDeliver($affiliate);

    // They now qualify to apply — but a rank widens what somebody may do, so
    // nobody gets one without being looked at.
    expect($affiliate->fresh()->tier_id)->toBe($starter->id);
});
