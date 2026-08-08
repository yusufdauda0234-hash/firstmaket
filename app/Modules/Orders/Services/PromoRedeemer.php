<?php

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\PromoCode;
use App\Modules\Orders\Models\PromoRedemption;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Checking, spending and releasing promo codes.
 *
 * Discounts are platform-funded: they come out of FirstMaket's commission and
 * never out of the vendor's earning. That is the whole reason the cap exists
 * — a discount larger than the commission on a basket is FirstMaket paying a
 * customer to shop, which is a decision nobody makes by typing a percentage
 * into a form.
 */
class PromoRedeemer
{
    /**
     * Check a code against a basket without spending it.
     *
     * Throws with a message meant for the customer. Deliberately specific
     * ("this code has expired" rather than "invalid"): a vague message on a
     * code somebody was legitimately given generates support contacts, and
     * the code itself is not a secret worth protecting — the apply endpoint
     * is rate-limited instead.
     *
     * @return array{code: PromoCode, discountKobo: int, deliveryDiscountKobo: int}
     */
    public function quote(User $user, string $rawCode, int $subtotalKobo, int $deliveryKobo = 0, int $commissionKobo = 0): array
    {
        $code = PromoCode::query()->where('code', strtoupper(trim($rawCode)))->first();

        if ($code === null || ! $code->is_active) {
            throw ValidationException::withMessages(['promo_code' => 'That code is not recognised.']);
        }

        if (! $code->isWithinSchedule()) {
            throw ValidationException::withMessages([
                'promo_code' => $code->starts_at?->isFuture()
                    ? 'That code is not active yet.'
                    : 'That code has expired.',
            ]);
        }

        if ($subtotalKobo < $code->min_order_kobo) {
            throw ValidationException::withMessages([
                'promo_code' => 'This code needs an order of at least ₦'
                    .number_format($code->min_order_kobo / 100).'.',
            ]);
        }

        if ($code->max_redemptions !== null
            && $code->liveRedemptions()->count() >= $code->max_redemptions) {
            throw ValidationException::withMessages(['promo_code' => 'That code has been fully claimed.']);
        }

        $usedByCustomer = $code->liveRedemptions()->where('user_id', $user->id)->count();

        if ($usedByCustomer >= $code->max_per_customer) {
            throw ValidationException::withMessages(['promo_code' => 'You have already used that code.']);
        }

        if ($code->first_order_only && Order::query()->where('customer_id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'promo_code' => 'That code is for first orders only.',
            ]);
        }

        $goodsDiscount = $code->type === 'free_delivery' ? 0 : $code->discountOn($subtotalKobo);
        $deliveryDiscount = $code->type === 'free_delivery' ? min($deliveryKobo, $code->discountOn(0, $deliveryKobo)) : 0;

        // The guard that keeps a promotion from becoming a loss. Commission is
        // what FirstMaket has to give away; beyond it the discount would come
        // out of the vendor's earning, which they never agreed to.
        if ($commissionKobo > 0 && $goodsDiscount > $commissionKobo) {
            $goodsDiscount = $commissionKobo;
        }

        return [
            'code' => $code,
            'discountKobo' => $goodsDiscount,
            'deliveryDiscountKobo' => $deliveryDiscount,
        ];
    }

    /**
     * Spend the code against one checkout.
     *
     * Row-locked and idempotent: two tabs submitting the last use of a code
     * at once must not both succeed, and a replayed request must not spend it
     * twice. The unique index on (code, checkout session) is the backstop if
     * both ever get past the lock.
     */
    public function redeem(User $user, PromoCode $code, int $checkoutSessionId, int $discountKobo): PromoRedemption
    {
        return DB::transaction(function () use ($user, $code, $checkoutSessionId, $discountKobo) {
            /** @var PromoCode $code */
            $code = PromoCode::query()->whereKey($code->id)->lockForUpdate()->firstOrFail();

            $existing = PromoRedemption::query()
                ->where('promo_code_id', $code->id)
                ->where('checkout_session_id', $checkoutSessionId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            // Re-checked under the lock: the count may have moved since the
            // customer was quoted.
            if ($code->max_redemptions !== null
                && $code->liveRedemptions()->count() >= $code->max_redemptions) {
                throw ValidationException::withMessages([
                    'promo_code' => 'That code was fully claimed while you were checking out.',
                ]);
            }

            return PromoRedemption::query()->create([
                'promo_code_id' => $code->id,
                'user_id' => $user->id,
                'checkout_session_id' => $checkoutSessionId,
                'discount_kobo' => $discountKobo,
            ]);
        });
    }

    /**
     * Give a use back after a refund or a vendor rejection.
     *
     * The customer did not get what they paid for, so they should not also
     * lose the code. Already-released redemptions are left alone.
     */
    public function release(int $checkoutSessionId): int
    {
        return PromoRedemption::query()
            ->where('checkout_session_id', $checkoutSessionId)
            ->whereNull('released_at')
            ->update(['released_at' => now()]);
    }

    /**
     * Split a basket discount across its units so the parts sum to exactly
     * the whole.
     *
     * Largest remainder, not naive rounding: ₦5,000 over three units is
     * 1666.67 each, and rounding each independently loses or invents a kobo.
     * The leftover goes to the units with the biggest fractional part, which
     * keeps every share within one kobo of its fair value.
     *
     * @param  array<int, int>  $unitPricesKobo  Keyed however the caller likes.
     * @return array<int, int> Same keys, shares summing to $discountKobo.
     */
    public function apportion(int $discountKobo, array $unitPricesKobo): array
    {
        $total = array_sum($unitPricesKobo);

        if ($total <= 0 || $discountKobo <= 0) {
            return array_map(fn () => 0, $unitPricesKobo);
        }

        $discountKobo = min($discountKobo, $total);

        $shares = [];
        $remainders = [];

        foreach ($unitPricesKobo as $key => $price) {
            $exact = $discountKobo * $price / $total;
            $shares[$key] = (int) floor($exact);
            $remainders[$key] = $exact - $shares[$key];
        }

        // Hand out what flooring left behind, biggest fractional part first.
        $leftover = $discountKobo - array_sum($shares);
        arsort($remainders);

        foreach (array_keys($remainders) as $key) {
            if ($leftover <= 0) {
                break;
            }

            $shares[$key]++;
            $leftover--;
        }

        return $shares;
    }
}
