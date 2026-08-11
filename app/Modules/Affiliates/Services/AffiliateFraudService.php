<?php

namespace App\Modules\Affiliates\Services;

use App\Models\Setting;
use App\Models\User;
use App\Modules\Affiliates\Models\AffiliateConversion;
use App\Modules\Affiliates\Models\AffiliateFraudFlag;

/**
 * Heuristics that put a conversion in front of a human.
 *
 * Nothing here suspends anybody or withholds money on its own. A flag moves
 * the conversion to `review`, which keeps it out of a payout batch until
 * staff decide — the cost of a false positive is then a short delay, not a
 * partner wrongly accused. Automatic suspension on a heuristic would also
 * hand anyone with a grudge a way to knock a competitor offline by sending
 * deliberately suspicious traffic at their link.
 *
 * Every threshold is a setting, because what looks abnormal depends on the
 * size of the programme and nobody knows the right number in advance.
 */
class AffiliateFraudService
{
    private const DEFAULTS = [
        // A referred account converting within minutes of signing up is more
        // likely to be the partner themselves than a shopper who browsed.
        'affiliates.fraud_min_minutes_to_convert' => 5,
        // How many times larger than the partner's own average an order can
        // be before it is worth a look.
        'affiliates.fraud_value_anomaly_multiple' => 10,
        // Below this many prior conversions there is no average worth
        // comparing against.
        'affiliates.fraud_minimum_history' => 5,
    ];

    /**
     * Inspect a freshly created conversion. Returns the flags raised, and
     * moves the conversion into review if there are any.
     *
     * @return list<AffiliateFraudFlag>
     */
    public function inspect(AffiliateConversion $conversion): array
    {
        $settings = array_map('intval', Setting::many(self::DEFAULTS));
        $flags = [];

        if ($reason = $this->selfDealing($conversion)) {
            $flags[] = $this->raise($conversion, AffiliateFraudFlag::REASON_SELF_DEALING, $reason);
        }

        if ($reason = $this->tooFast($conversion, $settings['affiliates.fraud_min_minutes_to_convert'])) {
            $flags[] = $this->raise($conversion, AffiliateFraudFlag::REASON_VELOCITY, $reason);
        }

        if ($reason = $this->valueAnomaly(
            $conversion,
            $settings['affiliates.fraud_value_anomaly_multiple'],
            $settings['affiliates.fraud_minimum_history'],
        )) {
            $flags[] = $this->raise($conversion, AffiliateFraudFlag::REASON_VALUE_ANOMALY, $reason);
        }

        if ($flags !== []) {
            $conversion->forceFill(['status' => AffiliateConversion::STATUS_REVIEW])->save();
        }

        return $flags;
    }

    public function resolve(AffiliateFraudFlag $flag, User $staff, string $status, ?string $note = null): AffiliateFraudFlag
    {
        $flag->forceFill([
            'status' => $status,
            'resolved_by' => $staff->id,
            'resolved_at' => now(),
            'resolution_note' => $note,
        ])->save();

        return $flag;
    }

    /**
     * The converting account belongs to the partner.
     *
     * attributeSignup() already refuses to attribute a partner to themselves,
     * so this catches the case it cannot see: an account created outside the
     * link and later attributed, or a shared email/phone identity.
     */
    private function selfDealing(AffiliateConversion $conversion): ?string
    {
        $affiliate = $conversion->affiliate;

        if ($affiliate === null) {
            return null;
        }

        if ($affiliate->user_id === $conversion->user_id) {
            return 'The converting account is the affiliate\'s own account.';
        }

        $affiliateUser = $affiliate->user;
        $convertingUser = User::query()->find($conversion->user_id);

        if ($affiliateUser === null || $convertingUser === null) {
            return null;
        }

        if ($affiliateUser->phone !== null && $affiliateUser->phone === $convertingUser->phone) {
            return 'The converting account shares a phone number with the affiliate.';
        }

        return null;
    }

    private function tooFast(AffiliateConversion $conversion, int $minMinutes): ?string
    {
        $attributedAt = $conversion->attribution?->created_at;

        if ($attributedAt === null) {
            return null;
        }

        $minutes = $attributedAt->diffInMinutes($conversion->qualified_at ?? now());

        if ($minutes >= $minMinutes) {
            return null;
        }

        return "Converted {$minutes} minute(s) after attribution, under the {$minMinutes} minute floor.";
    }

    private function valueAnomaly(AffiliateConversion $conversion, int $multiple, int $minimumHistory): ?string
    {
        if ($conversion->order_value_kobo <= 0) {
            return null;
        }

        $history = AffiliateConversion::query()
            ->where('affiliate_id', $conversion->affiliate_id)
            ->where('id', '!=', $conversion->id)
            ->where('status', AffiliateConversion::STATUS_QUALIFIED)
            ->where('order_value_kobo', '>', 0);

        if ((clone $history)->count() < $minimumHistory) {
            return null;
        }

        $average = (float) (clone $history)->avg('order_value_kobo');

        if ($average <= 0 || $conversion->order_value_kobo < $average * $multiple) {
            return null;
        }

        return 'Order value is '.(int) round($conversion->order_value_kobo / $average)
            .'x this affiliate\'s average.';
    }

    private function raise(AffiliateConversion $conversion, string $reason, string $detail): AffiliateFraudFlag
    {
        return AffiliateFraudFlag::query()->create([
            'affiliate_id' => $conversion->affiliate_id,
            'conversion_id' => $conversion->id,
            'reason' => $reason,
            'detail' => $detail,
            'status' => AffiliateFraudFlag::STATUS_OPEN,
        ]);
    }
}
