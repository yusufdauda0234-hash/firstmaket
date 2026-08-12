<?php

namespace App\Modules\Affiliates\Services;

use App\Models\Setting;
use App\Models\User;
use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliateAttribution;
use App\Modules\Affiliates\Models\AffiliateClick;
use App\Modules\Affiliates\Models\AffiliateCommission;
use App\Modules\Affiliates\Models\AffiliateConversion;
use App\Modules\Affiliates\Models\AffiliateLink;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Modules\Vendor\Models\VendorProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The partner program: who may refer, through which links, and what a
 * referral is worth.
 *
 * Three rules hold everywhere in here, because each one is a way partners
 * could otherwise be paid for work they did not do:
 *
 *  1. **First attribution wins, forever.** One row per user, created once.
 *     A later click by the same customer never re-points them at a different
 *     partner.
 *  2. **Attribution expires.** A click today does not earn on an order two
 *     years from now. The window is stamped onto the attribution when it is
 *     created, so shortening the setting later cannot void what partners
 *     already earned under the old rule.
 *  3. **A suspended partner earns nothing new.** Checked at qualification
 *     time, not only at click time — a partner suspended between the click
 *     and the delivery must not still be paid for it.
 */
class AffiliateService
{
    public const DEFAULT_COMMISSION_PERCENT = 5;

    /** How long an attribution keeps earning, in days. */
    public const DEFAULT_ATTRIBUTION_WINDOW_DAYS = 90;

    public function __construct(
        private readonly AffiliateTierResolver $tiers,
        private readonly AffiliateFraudService $fraud,
        private readonly AffiliateRankService $ranks,
    ) {}

    public function commissionPercent(): float
    {
        return (float) Setting::get('affiliates.commission_percent', self::DEFAULT_COMMISSION_PERCENT);
    }

    public function clickDedupeHours(): int
    {
        return (int) Setting::get('affiliates.click_dedupe_hours', 24);
    }

    public function attributionWindowDays(): int
    {
        return max(1, (int) Setting::get('affiliates.attribution_window_days', self::DEFAULT_ATTRIBUTION_WINDOW_DAYS));
    }

    public function apply(User $user, string $displayName): Affiliate
    {
        return Affiliate::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['display_name' => $displayName, 'status' => Affiliate::STATUS_PENDING, 'rejection_reason' => null],
        );
    }

    public function approve(Affiliate $affiliate, User $admin): AffiliateLink
    {
        return DB::transaction(function () use ($affiliate, $admin): AffiliateLink {
            $entryRank = $this->ranks->entryRank();

            $affiliate->forceFill([
                'status' => Affiliate::STATUS_APPROVED,
                'approved_by' => $admin->id,
                'approved_at' => now(),
                'rejection_reason' => null,
                'suspended_at' => null,
                'suspension_reason' => null,
                'tier_id' => $affiliate->tier_id ?? $entryRank?->id,
                // The quota window opens now, not at signup — otherwise an
                // application that sat waiting for a week would arrive with
                // part of its allowance already counted against it.
                'rank_entered_at' => $affiliate->rank_entered_at ?? now(),
            ])->save();

            $existing = $affiliate->links()->first();

            return $existing ?? $this->createLink($affiliate, 'Main link', null);
        });
    }

    public function reject(Affiliate $affiliate, string $reason): void
    {
        $affiliate->forceFill(['status' => Affiliate::STATUS_REJECTED, 'rejection_reason' => $reason])->save();
        $affiliate->links()->update(['status' => AffiliateLink::STATUS_SUSPENDED]);
    }

    /**
     * Stop a partner trading without erasing what they have already earned.
     *
     * Commissions already banked stay payable; new attribution and new
     * qualification both refuse while the suspension stands.
     */
    public function suspend(Affiliate $affiliate, string $reason): void
    {
        $affiliate->forceFill([
            'status' => Affiliate::STATUS_SUSPENDED,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ])->save();
        $affiliate->links()->update(['status' => AffiliateLink::STATUS_SUSPENDED]);
    }

    public function reinstate(Affiliate $affiliate): void
    {
        $affiliate->forceFill([
            'status' => Affiliate::STATUS_APPROVED,
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();
        $affiliate->links()->update(['status' => AffiliateLink::STATUS_ACTIVE]);
    }

    // ── Links ───────────────────────────────────────────────────────────────

    /**
     * A new tracking link. Every link carries an HMAC of its own code, so a
     * URL edited by hand fails verification instead of silently attributing
     * to whatever code the editor guessed.
     */
    public function createLink(Affiliate $affiliate, string $label, ?string $campaign, ?\DateTimeInterface $expiresAt = null): AffiliateLink
    {
        if (! $affiliate->isActive()) {
            throw ValidationException::withMessages(['link' => 'Only an active affiliate can create links.']);
        }

        if (! $this->ranks->canCreateLink($affiliate)) {
            throw ValidationException::withMessages([
                'link' => 'You have as many live links as your rank allows. Switch one off, or upgrade for more.',
            ]);
        }

        /*
         * The rank decides how long a link lives. A partner may ask for
         * something shorter, but never longer — short-lived links at the
         * bottom of the ladder are what limit the damage somebody can do
         * before anyone has verified who they are.
         */
        $rankExpiry = $this->ranks->linkExpiryFor($affiliate);

        if ($rankExpiry !== null) {
            $expiresAt = $expiresAt === null
                ? $rankExpiry
                : min($expiresAt, $rankExpiry);
        }

        $code = $this->newCode();

        return $affiliate->links()->create([
            'code' => $code,
            'signature' => $this->signatureFor($code),
            'label' => $label,
            'campaign' => $campaign,
            'status' => AffiliateLink::STATUS_ACTIVE,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Truncated HMAC — long enough that guessing is hopeless, short enough
     * for a URL.
     *
     * Signed with its own key rather than APP_KEY where one is configured.
     * Affiliate links get printed on flyers and pasted into bios, and they
     * have to keep working for years; signing them with the application key
     * would mean an APP_KEY rotation silently invalidated every live campaign
     * link at once. Falls back to APP_KEY so an install that has not set the
     * dedicated key still works.
     */
    public function signatureFor(string $code): string
    {
        $key = (string) (config('services.affiliates.tracking_signing_key') ?: config('app.key'));

        return substr(hash_hmac('sha256', $code, $key), 0, 32);
    }

    public function verifySignature(AffiliateLink $link, ?string $signature): bool
    {
        // Links created before signing existed have none to check. They stay
        // valid rather than breaking live campaigns; new links always carry one.
        if ($link->signature === null) {
            return true;
        }

        return $signature !== null && hash_equals($link->signature, $signature);
    }

    // ── Click and attribution ───────────────────────────────────────────────

    public function capture(string $code, string $ip, string $userAgent, ?string $signature = null): ?AffiliateLink
    {
        $link = AffiliateLink::query()->with('affiliate')
            ->where('code', $code)
            ->first();

        if ($link === null
            || ! $link->isUsable()
            || $link->affiliate === null
            || ! $link->affiliate->isActive()
            || ! $this->verifySignature($link, $signature)
        ) {
            return null;
        }

        $ipHash = hash_hmac('sha256', $ip, (string) config('app.key'));
        $fingerprintHash = hash_hmac('sha256', $userAgent, (string) config('app.key'));
        $recent = AffiliateClick::query()
            ->where('affiliate_link_id', $link->id)
            ->where('ip_hash', $ipHash)
            ->where('fingerprint_hash', $fingerprintHash)
            ->where('created_at', '>=', now()->subHours($this->clickDedupeHours()))
            ->exists();

        if (! $recent) {
            AffiliateClick::query()->create([
                'affiliate_link_id' => $link->id,
                'ip_hash' => $ipHash,
                'fingerprint_hash' => $fingerprintHash,
                'created_at' => now(),
            ]);
        }

        return $link;
    }

    /**
     * Bind a new account to the link it arrived through, and record the
     * signup as its own conversion — partners are measured on signups, not
     * only on the orders that follow much later.
     */
    public function attributeSignup(User $user, int $linkId): void
    {
        $link = AffiliateLink::query()->with('affiliate')->find($linkId);

        if ($link === null
            || ! $link->isUsable()
            || $link->affiliate === null
            || ! $link->affiliate->isActive()
            // Self-referral: signing yourself up through your own link.
            || $link->affiliate->user_id === $user->id
        ) {
            return;
        }

        DB::transaction(function () use ($user, $link): void {
            $attribution = AffiliateAttribution::query()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'affiliate_link_id' => $link->id,
                    'token' => Str::random(48),
                    'expires_at' => now()->addDays($this->attributionWindowDays()),
                ],
            );

            // wasRecentlyCreated is the guard against a second link claiming
            // an existing customer: firstOrCreate returns the original row.
            if (! $attribution->wasRecentlyCreated) {
                return;
            }

            $this->recordConversion(
                $link->affiliate,
                $attribution,
                $user->id,
                AffiliateConversion::TYPE_SIGNUP,
                0,
            );
        });
    }

    /** A referred account finishing phone verification — a stronger signal than a bare signup. */
    public function qualifyVerifiedUser(User $user): void
    {
        $attribution = $this->earningAttributionFor($user->id);

        if ($attribution === null) {
            return;
        }

        $this->recordConversion(
            $attribution->link->affiliate,
            $attribution,
            $user->id,
            AffiliateConversion::TYPE_VERIFIED_USER,
            0,
        );
    }

    // ── Qualification ───────────────────────────────────────────────────────

    public function qualifyDeliveredOrder(Order $order): void
    {
        $attribution = $this->earningAttributionFor($order->customer_id);

        if ($attribution === null) {
            return;
        }

        $affiliate = $attribution->link->affiliate;

        // An order raised off a completed Pay Small Small plan is worth
        // recording as its own conversion type: it is the harder sale, and
        // reporting on the two together would hide that.
        $type = $order->savings_goal_id !== null
            ? AffiliateConversion::TYPE_COMPLETED_PLAN_ORDER
            : AffiliateConversion::TYPE_DELIVERED_ORDER;

        DB::transaction(function () use ($order, $attribution, $affiliate, $type): void {
            $conversion = AffiliateConversion::query()->firstOrCreate(
                ['order_id' => $order->id],
                [
                    'affiliate_id' => $affiliate->id,
                    'affiliate_attribution_id' => $attribution->id,
                    'user_id' => $order->customer_id,
                    'conversion_type' => $type,
                    'status' => AffiliateConversion::STATUS_QUALIFIED,
                    'order_value_kobo' => $order->locked_price_kobo,
                    'qualified_at' => now(),
                ],
            );

            if (! $conversion->wasRecentlyCreated) {
                return;
            }

            $this->fraud->inspect($conversion);
            $this->writeCommission($affiliate, $conversion);
        });
    }

    public function qualifyApprovedVendorProduct(Product $product): void
    {
        $vendor = VendorProfile::query()->find($product->vendor_id);

        if ($vendor === null || $vendor->approved_at === null || $product->approved_at === null || $vendor->approved_at->gt($product->approved_at)) {
            return;
        }

        $attribution = $this->earningAttributionFor($vendor->user_id);

        if ($attribution === null) {
            return;
        }

        $affiliate = $attribution->link->affiliate;

        DB::transaction(function () use ($product, $attribution, $affiliate): void {
            $alreadyConverted = AffiliateConversion::query()
                ->where('affiliate_id', $affiliate->id)
                ->where('user_id', $vendorUserId = $attribution->user_id)
                ->where('conversion_type', AffiliateConversion::TYPE_VENDOR_PRODUCT)
                ->exists();

            if ($alreadyConverted) {
                return;
            }

            $conversion = $this->recordConversion(
                $affiliate,
                $attribution,
                $vendorUserId,
                AffiliateConversion::TYPE_VENDOR_PRODUCT,
                $product->price_kobo,
            );

            $this->fraud->inspect($conversion);
            $this->writeCommission($affiliate, $conversion);
        });
    }

    /**
     * Reject a conversion during review: the commission behind it is voided
     * rather than deleted, so the decision stays on the record. A commission
     * already paid out is left alone — clawback is a separate decision, not
     * something a review screen should do silently.
     */
    public function rejectConversion(AffiliateConversion $conversion, string $reason): void
    {
        DB::transaction(function () use ($conversion, $reason): void {
            $conversion->forceFill(['status' => AffiliateConversion::STATUS_REJECTED])->save();

            $conversion->commissions()
                ->whereIn('status', [AffiliateCommission::STATUS_PENDING, AffiliateCommission::STATUS_BATCHED])
                ->update(['status' => AffiliateCommission::STATUS_VOID, 'payout_item_id' => null]);

            $conversion->fraudFlags()
                ->where('status', \App\Modules\Affiliates\Models\AffiliateFraudFlag::STATUS_OPEN)
                ->update([
                    'status' => \App\Modules\Affiliates\Models\AffiliateFraudFlag::STATUS_CONFIRMED,
                    'resolution_note' => $reason,
                    'resolved_at' => now(),
                ]);
        });
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * The attribution that may still earn for this user: it exists, it is
     * inside its window, and the partner behind it is trading.
     */
    private function earningAttributionFor(int $userId): ?AffiliateAttribution
    {
        $attribution = AffiliateAttribution::query()
            ->with('link.affiliate')
            ->where('user_id', $userId)
            ->first();

        if ($attribution === null
            || ! $attribution->isWithinWindow()
            || $attribution->link?->affiliate === null
            || ! $attribution->link->affiliate->isActive()
        ) {
            return null;
        }

        return $attribution;
    }

    private function recordConversion(
        Affiliate $affiliate,
        AffiliateAttribution $attribution,
        int $userId,
        string $type,
        int $valueKobo,
    ): AffiliateConversion {
        return AffiliateConversion::query()->create([
            'affiliate_id' => $affiliate->id,
            'affiliate_attribution_id' => $attribution->id,
            'user_id' => $userId,
            'conversion_type' => $type,
            'status' => AffiliateConversion::STATUS_QUALIFIED,
            'order_value_kobo' => $valueKobo,
            'qualified_at' => now(),
        ]);
    }

    /**
     * Price a qualified conversion and bank it as a pending commission.
     *
     * A conversion put into review by the fraud check is not priced at all —
     * a commission row that exists is a commission somebody expects to be
     * paid, and creating one for traffic under suspicion invites an argument
     * later.
     */
    private function writeCommission(Affiliate $affiliate, AffiliateConversion $conversion): ?AffiliateCommission
    {
        if ($conversion->status !== AffiliateConversion::STATUS_QUALIFIED) {
            return null;
        }

        /*
         * The rank's referral quota.
         *
         * The conversion is still recorded — the referral genuinely happened
         * and the partner should see it on their funnel — but nothing is paid
         * for it beyond the allowance their rank carries. They upgrade to
         * keep earning.
         *
         * Checked here rather than at attribution time on purpose: a customer
         * who clicked a link must still be able to sign up and shop normally.
         * Refusing them would cost a sale to make a point at the partner.
         */
        if (! $this->ranks->canEarn($affiliate, $conversion->id)) {
            return null;
        }

        $amount = $this->tiers->commissionFor($affiliate, $conversion);

        if ($amount <= 0) {
            return null;
        }

        return AffiliateCommission::query()->firstOrCreate(
            ['conversion_id' => $conversion->id],
            [
                'affiliate_id' => $affiliate->id,
                'amount_kobo' => $amount,
                'status' => AffiliateCommission::STATUS_PENDING,
            ],
        );
    }

    private function newCode(): string
    {
        do {
            $code = Str::upper(Str::random(16));
        } while (AffiliateLink::query()->where('code', $code)->exists());

        return $code;
    }
}
