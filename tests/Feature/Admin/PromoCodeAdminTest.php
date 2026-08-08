<?php

use App\Models\User;
use App\Modules\Cart\Models\CheckoutSession;
use App\Modules\Orders\Models\PromoCode;
use App\Modules\Orders\Models\PromoRedemption;
use App\Shared\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * The admin promo-codes screen.
 *
 * Behind commissions.manage, because a discount is spent out of the
 * commission — whoever may set the cut may give it away.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

function promoAdmin(): User
{
    $admin = User::factory()->create([
        'user_type' => UserType::Staff,
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('Administrator');

    return $admin;
}

function promoAdminUrl(string $path = ''): string
{
    return 'http://'.strtolower((string) config('app.admin_domain')).'/settings/promo-codes'.$path;
}

function newCodePayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'LAUNCH20',
        'type' => 'percent',
        'percent_off' => 20,
        'max_discount_naira' => 5000,
        'max_per_customer' => 1,
    ], $overrides);
}

// ── Who may see it ──────────────────────────────────────────────────────

it('shows the codes to an administrator', function () {
    PromoCode::query()->create([
        'code' => 'SAVE10', 'type' => 'percent', 'percent_off' => '10.00',
        'max_discount_kobo' => 10_000_00, 'is_active' => true,
    ]);

    $this->actingAs(promoAdmin())
        ->get(promoAdminUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/PromoCodes')
            ->has('codes', 1)
            ->where('codes.0.code', 'SAVE10')
            ->where('codes.0.status', 'live'));
});

it('keeps a customer out', function () {
    $customer = User::factory()->create();
    $customer->assignRole('Customer');

    // 302, not 403: EnsureCorrectPortal sends a customer off the admin domain
    // before any permission check runs.
    $this->actingAs($customer)->get(promoAdminUrl())->assertStatus(302);
});

// ── Creating ────────────────────────────────────────────────────────────

it('creates a code', function () {
    $this->actingAs(promoAdmin())
        ->post(promoAdminUrl(), newCodePayload())
        ->assertRedirect();

    $code = PromoCode::query()->firstOrFail();

    expect($code->code)->toBe('LAUNCH20')
        ->and($code->max_discount_kobo)->toBe(5_000_00)
        ->and($code->is_active)->toBeTrue();
});

it('stores a lower-case code upper-case', function () {
    $this->actingAs(promoAdmin())->post(promoAdminUrl(), newCodePayload(['code' => 'launch20']));

    expect(PromoCode::query()->value('code'))->toBe('LAUNCH20');
});

it('refuses a percentage code with no ceiling', function () {
    // Without a cap, one expensive order can spend the whole campaign.
    $this->actingAs(promoAdmin())
        ->post(promoAdminUrl(), newCodePayload(['max_discount_naira' => null]))
        ->assertSessionHasErrors('max_discount_naira');

    expect(PromoCode::query()->count())->toBe(0);
});

it('refuses a code with punctuation in it', function () {
    $this->actingAs(promoAdmin())
        ->post(promoAdminUrl(), newCodePayload(['code' => 'SAVE-10!']))
        ->assertSessionHasErrors('code');
});

it('refuses a duplicate code whatever case it is typed in', function () {
    PromoCode::query()->create([
        'code' => 'LAUNCH20', 'type' => 'percent', 'percent_off' => '10.00',
        'max_discount_kobo' => 10_000_00, 'is_active' => true,
    ]);

    $this->actingAs(promoAdmin())
        ->post(promoAdminUrl(), newCodePayload(['code' => 'launch20']))
        ->assertSessionHasErrors('code');
});

it('refuses an end date before the start', function () {
    $this->actingAs(promoAdmin())
        ->post(promoAdminUrl(), newCodePayload([
            'starts_at' => '2026-09-01',
            'ends_at' => '2026-08-01',
        ]))
        ->assertSessionHasErrors('ends_at');
});

it('refuses a fixed code with no amount', function () {
    $this->actingAs(promoAdmin())
        ->post(promoAdminUrl(), newCodePayload([
            'type' => 'fixed', 'percent_off' => null, 'max_discount_naira' => null,
        ]))
        ->assertSessionHasErrors('amount_off_naira');
});

// ── Editing and switching off ───────────────────────────────────────────

it('updates a code', function () {
    $code = PromoCode::query()->create([
        'code' => 'SAVE10', 'type' => 'percent', 'percent_off' => '10.00',
        'max_discount_kobo' => 10_000_00, 'is_active' => true,
    ]);

    $this->actingAs(promoAdmin())
        ->put(promoAdminUrl('/'.$code->uuid), newCodePayload([
            'code' => 'SAVE10', 'percent_off' => 15, 'max_discount_naira' => 8000,
        ]))
        ->assertRedirect();

    expect((float) $code->fresh()->percent_off)->toBe(15.0)
        ->and($code->fresh()->max_discount_kobo)->toBe(8_000_00);
});

it('switches a code off instead of deleting it', function () {
    $customer = User::factory()->create();
    $code = PromoCode::query()->create([
        'code' => 'SAVE10', 'type' => 'percent', 'percent_off' => '10.00',
        'max_discount_kobo' => 10_000_00, 'is_active' => true,
    ]);
    $session = CheckoutSession::query()->create([
        'user_id' => $customer->id, 'total_amount_kobo' => 0,
        'delivery_address' => '12 Marina Road', 'state' => 'Lagos', 'lga' => 'Eti-Osa',
        'status' => 'paid',
    ]);
    PromoRedemption::query()->create([
        'promo_code_id' => $code->id, 'user_id' => $customer->id,
        'checkout_session_id' => $session->id, 'discount_kobo' => 5_000_00,
    ]);

    $this->actingAs(promoAdmin())
        ->delete(promoAdminUrl('/'.$code->uuid))
        ->assertRedirect();

    // The row survives, and so does the record of what it cost — deleting it
    // would cascade the redemptions away and let everybody redeem again.
    expect($code->fresh()->is_active)->toBeFalse()
        ->and(PromoRedemption::query()->count())->toBe(1);
});

// ── What the table reports ──────────────────────────────────────────────

it('reports what a campaign has cost', function () {
    $customer = User::factory()->create();
    $code = PromoCode::query()->create([
        'code' => 'SAVE10', 'type' => 'percent', 'percent_off' => '10.00',
        'max_discount_kobo' => 10_000_00, 'is_active' => true,
    ]);

    foreach ([5_000_00, 3_000_00] as $discount) {
        $session = CheckoutSession::query()->create([
            'user_id' => $customer->id, 'total_amount_kobo' => 0,
            'delivery_address' => '12 Marina Road', 'state' => 'Lagos', 'lga' => 'Eti-Osa',
            'status' => 'paid',
        ]);
        PromoRedemption::query()->create([
            'promo_code_id' => $code->id, 'user_id' => $customer->id,
            'checkout_session_id' => $session->id, 'discount_kobo' => $discount,
        ]);
    }

    $this->actingAs(promoAdmin())
        ->get(promoAdminUrl())
        ->assertInertia(fn ($page) => $page
            ->where('codes.0.redemptionCount', 2)
            ->where('codes.0.spendNaira', 8000));
});

it('says why a code is not usable', function () {
    PromoCode::query()->create([
        'code' => 'GONE', 'type' => 'percent', 'percent_off' => '10.00',
        'max_discount_kobo' => 10_000_00, 'is_active' => true,
        'ends_at' => now()->subDay(),
    ]);

    // "Expired", not "off" — an admin looking at a code a customer says is
    // broken needs to be told which of the several reasons it is.
    $this->actingAs(promoAdmin())
        ->get(promoAdminUrl())
        ->assertInertia(fn ($page) => $page->where('codes.0.status', 'expired'));
});
