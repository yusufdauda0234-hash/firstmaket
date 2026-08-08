<?php

namespace App\Modules\Orders\Services;

use App\Models\Setting;
use App\Models\User;
use App\Modules\Logistics\Models\Shipment;
use App\Shared\Enums\DeliveryOutcome;

/**
 * Whether a given basket may be paid for at the door.
 *
 * Cash on delivery costs a wasted round trip every time somebody changes
 * their mind, and it puts real notes in a courier's pocket. So it is off out
 * of the box and everything about it is a setting — on/off, a ceiling, which
 * states, how many refusals a customer gets — because the right answer to all
 * four changes as the business learns, and none of them should need a deploy.
 */
class PayOnDeliveryPolicy
{
    /** Sensible starting points. Every one is editable in admin. */
    public const DEFAULT_MAX_KOBO = 50_000_00;

    public const DEFAULT_MAX_REFUSALS = 3;

    public static function isEnabled(): bool
    {
        return (bool) Setting::get('orders.pay_on_delivery_enabled', false);
    }

    /** The most a basket may be worth and still be paid for at the door. */
    public static function maxOrderKobo(): int
    {
        return (int) Setting::get('orders.pay_on_delivery_max_kobo', self::DEFAULT_MAX_KOBO);
    }

    /**
     * The states it is offered in. Empty means everywhere.
     *
     * @return array<int, string>
     */
    public static function states(): array
    {
        return (array) Setting::get('orders.pay_on_delivery_states', []);
    }

    public static function maxRefusals(): int
    {
        return (int) Setting::get('orders.pay_on_delivery_max_refusals', self::DEFAULT_MAX_REFUSALS);
    }

    /**
     * Why this customer cannot use it, or null if they can.
     *
     * Returns the reason rather than a bare false so the checkout screen can
     * say which limit was hit — "we don't deliver cash-on-delivery to Yobe"
     * and "your basket is too big for it" are different problems, and a
     * shopper told only "unavailable" will assume the site is broken.
     */
    public static function refusalReason(?User $customer, int $subtotalKobo, ?string $state): ?string
    {
        if (! self::isEnabled()) {
            return 'Pay on delivery is not being offered at the moment.';
        }

        $max = self::maxOrderKobo();

        if ($max > 0 && $subtotalKobo > $max) {
            return 'Pay on delivery is for orders up to ₦'.number_format($max / 100)
                .'. This one is above that, so it needs paying for now.';
        }

        $states = self::states();

        if ($states !== [] && $state !== null && ! in_array($state, $states, true)) {
            return 'We do not offer pay on delivery in '.$state.' yet.';
        }

        if ($customer !== null && self::refusalCount($customer) >= self::maxRefusals()) {
            // Someone who has turned the courier away repeatedly has cost a
            // wasted trip each time. Said plainly rather than hidden, because
            // they can still buy — just not this way.
            return 'Pay on delivery is no longer available on this account. Orders paid for upfront are unaffected.';
        }

        return null;
    }

    public static function isAvailableFor(?User $customer, int $subtotalKobo, ?string $state): bool
    {
        return self::refusalReason($customer, $subtotalKobo, $state) === null;
    }

    /**
     * How many times this customer has turned a courier away at the door.
     *
     * Counted from the delivery attempts themselves rather than a column on
     * the user, so it cannot drift from what actually happened.
     */
    public static function refusalCount(User $customer): int
    {
        return Shipment::query()
            ->where('customer_id', $customer->id)
            ->whereHas(
                'attempts',
                fn ($query) => $query->where('outcome', DeliveryOutcome::Refused),
            )
            ->count();
    }
}
