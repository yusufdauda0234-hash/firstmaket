<?php

namespace App\Modules\Affiliates\Services;

use App\Models\User;
use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliateConversion;
use App\Modules\Affiliates\Models\AffiliateTier;
use App\Modules\Affiliates\Models\AffiliateUpgradeAnswer;
use App\Modules\Affiliates\Models\AffiliateUpgradeRequest;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The rank ladder: how far a partner may go before somebody checks who they
 * are.
 *
 * Each rank allows a fixed number of referrals. Once they are used the
 * partner stops earning new commission until they apply for the next rank
 * and a human approves it, having looked at whatever that rank asks for.
 *
 * Two decisions worth stating, because both could reasonably have gone the
 * other way:
 *
 * - **A used-up quota pauses earning, it does not switch links off.** The
 *   customer who clicks a link has done nothing wrong, and dropping them on
 *   a dead page to punish the partner would cost a sale to make a point.
 *   They shop normally; the partner simply is not credited.
 * - **Ranks are granted, not earned automatically.** Thresholds still exist,
 *   but they now say "you may apply", not "you are promoted". A bigger quota
 *   and a longer link life are exactly the leverage somebody running a scam
 *   wants, so nobody gets them without being looked at.
 */
class AffiliateRankService
{
    public function __construct(private readonly AuditLoggerContract $auditLogger) {}

    /** The rank a partner is actually on. */
    public function currentRank(Affiliate $affiliate): ?AffiliateTier
    {
        return $affiliate->tier ?? $this->entryRank();
    }

    /** The rank everybody starts on when they are first approved. */
    public function entryRank(): ?AffiliateTier
    {
        return AffiliateTier::query()->where('is_active', true)->where('is_default', true)->first()
            ?? AffiliateTier::query()->where('is_active', true)->orderBy('sort_order')->first();
    }

    /**
     * Referrals counted against the current rank's quota.
     *
     * Counted from the conversion id recorded when the partner entered the
     * rank, so upgrading genuinely resets the allowance rather than leaving
     * somebody permanently capped by work they did two ranks ago.
     *
     * An id rather than a timestamp because timestamps here are accurate to
     * the second: a partner upgrading in the same second a referral lands
     * would otherwise have it fall on whichever side of the boundary the
     * comparison happened to choose. Ids are monotonic, so there is one
     * answer.
     *
     * `$excludingConversionId` exists because a conversion row is written
     * before it is priced. Without it the referral being considered would
     * count towards its own quota, and a rank allowing three would pay for
     * two — the same off-by-one the commission tiers had.
     */
    public function referralsUsed(Affiliate $affiliate, ?int $excludingConversionId = null): int
    {
        return $affiliate->conversions()
            ->whereIn('status', [AffiliateConversion::STATUS_QUALIFIED, AffiliateConversion::STATUS_REVIEW])
            ->whereIn('conversion_type', [
                AffiliateConversion::TYPE_DELIVERED_ORDER,
                AffiliateConversion::TYPE_COMPLETED_PLAN_ORDER,
                AffiliateConversion::TYPE_VENDOR_PRODUCT,
            ])
            ->when(
                $affiliate->rank_baseline_conversion_id !== null,
                fn ($query) => $query->where('id', '>', $affiliate->rank_baseline_conversion_id),
            )
            ->when($excludingConversionId !== null, fn ($query) => $query->whereKeyNot($excludingConversionId))
            ->count();
    }

    /** How many are left before the partner has to upgrade. Null means unlimited. */
    public function referralsRemaining(Affiliate $affiliate, ?int $excludingConversionId = null): ?int
    {
        $rank = $this->currentRank($affiliate);

        if ($rank === null || $rank->hasUnlimitedReferrals()) {
            return null;
        }

        return max(0, $rank->referral_quota - $this->referralsUsed($affiliate, $excludingConversionId));
    }

    /**
     * Whether a referral may still earn.
     *
     * The one question the qualification path asks. Everything else on this
     * screen is presentation.
     */
    public function canEarn(Affiliate $affiliate, ?int $excludingConversionId = null): bool
    {
        $remaining = $this->referralsRemaining($affiliate, $excludingConversionId);

        return $remaining === null || $remaining > 0;
    }

    /** True once the quota is spent and there is somewhere to go. */
    public function mustUpgrade(Affiliate $affiliate): bool
    {
        return ! $this->canEarn($affiliate) && $this->nextRankFor($affiliate) !== null;
    }

    public function nextRankFor(Affiliate $affiliate): ?AffiliateTier
    {
        return $this->currentRank($affiliate)?->nextRank();
    }

    /**
     * How long a link created right now should live, given the partner's rank.
     * Null means it never expires.
     */
    public function linkExpiryFor(Affiliate $affiliate): ?\DateTimeInterface
    {
        $rank = $this->currentRank($affiliate);

        if ($rank === null || $rank->linksNeverExpire()) {
            return null;
        }

        return now()->addDays($rank->link_expiry_days);
    }

    /** Whether the partner may create another link at their rank. */
    public function canCreateLink(Affiliate $affiliate): bool
    {
        $rank = $this->currentRank($affiliate);
        $ceiling = (int) ($rank?->max_active_links ?? 0);

        if ($ceiling <= 0) {
            return true;
        }

        return $affiliate->links()->where('status', 'active')->count() < $ceiling;
    }

    /**
     * Put a partner on a rank. Used when they are first approved, and again
     * whenever an upgrade is granted.
     */
    public function assignRank(Affiliate $affiliate, AffiliateTier $rank): void
    {
        $affiliate->forceFill([
            'tier_id' => $rank->id,
            'rank_entered_at' => now(),
            // Resets the quota window: the allowance belongs to the rank, not
            // to the partner's whole history. Everything already recorded sits
            // at or below this id and is therefore behind them.
            'rank_baseline_conversion_id' => (int) $affiliate->conversions()->max('id'),
        ])->save();
    }

    // ── Upgrading ───────────────────────────────────────────────────────────

    /**
     * Submit an application for the next rank.
     *
     * @param  array<int, array{value?: string|null, document_id?: int|null}>  $answers  Keyed by requirement id.
     */
    public function requestUpgrade(Affiliate $affiliate, array $answers): AffiliateUpgradeRequest
    {
        if (! $affiliate->isActive()) {
            throw ValidationException::withMessages(['upgrade' => 'Only an active partner can apply to upgrade.']);
        }

        $target = $this->nextRankFor($affiliate);

        if ($target === null) {
            throw ValidationException::withMessages(['upgrade' => 'You are already on the highest rank.']);
        }

        if ($affiliate->upgradeRequests()->where('status', AffiliateUpgradeRequest::STATUS_PENDING)->exists()) {
            throw ValidationException::withMessages([
                'upgrade' => 'You already have an application waiting to be reviewed.',
            ]);
        }

        // Everything the rank marks required has to be answered. Checked here
        // rather than only in the form, because the form is not the only way
        // a request could arrive.
        foreach ($target->requirements as $requirement) {
            $answer = $answers[$requirement->id] ?? null;
            $provided = trim((string) ($answer['value'] ?? '')) !== ''
                || ($answer['document_id'] ?? null) !== null;

            if ($requirement->is_required && ! $provided) {
                throw ValidationException::withMessages([
                    'upgrade' => "\"{$requirement->label}\" is needed before this application can be sent.",
                ]);
            }
        }

        return DB::transaction(function () use ($affiliate, $target, $answers) {
            $request = AffiliateUpgradeRequest::query()->create([
                'affiliate_id' => $affiliate->id,
                'from_tier_id' => $this->currentRank($affiliate)?->id,
                'to_tier_id' => $target->id,
                'status' => AffiliateUpgradeRequest::STATUS_PENDING,
            ]);

            foreach ($target->requirements as $requirement) {
                $answer = $answers[$requirement->id] ?? null;

                AffiliateUpgradeAnswer::query()->create([
                    'request_id' => $request->id,
                    'requirement_id' => $requirement->id,
                    'value' => $answer['value'] ?? null,
                    'uploaded_document_id' => $answer['document_id'] ?? null,
                ]);
            }

            return $request;
        });
    }

    public function approveUpgrade(User $staff, AffiliateUpgradeRequest $request): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages(['request' => 'That application has already been decided.']);
        }

        DB::transaction(function () use ($staff, $request) {
            $request->forceFill([
                'status' => AffiliateUpgradeRequest::STATUS_APPROVED,
                'reviewed_by' => $staff->id,
                'reviewed_at' => now(),
            ])->save();

            $this->assignRank($request->affiliate, $request->toTier);

            $this->auditLogger->log(
                actor: $staff,
                subject: $request->affiliate,
                action: 'affiliate.rank_upgraded',
                newValues: ['to_rank' => $request->toTier->name],
            );
        });
    }

    public function rejectUpgrade(User $staff, AffiliateUpgradeRequest $request, string $reason): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages(['request' => 'That application has already been decided.']);
        }

        $request->forceFill([
            'status' => AffiliateUpgradeRequest::STATUS_REJECTED,
            'reviewed_by' => $staff->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ])->save();

        $this->auditLogger->log(
            actor: $staff,
            subject: $request->affiliate,
            action: 'affiliate.rank_upgrade_rejected',
            newValues: ['reason' => $reason, 'to_rank' => $request->toTier->name],
        );
    }

    /**
     * A partner's standing, for their own dashboard.
     *
     * @return array<string, mixed>
     */
    public function standing(Affiliate $affiliate): array
    {
        $rank = $this->currentRank($affiliate);
        $next = $this->nextRankFor($affiliate);
        $remaining = $this->referralsRemaining($affiliate);

        return [
            'rank' => $rank === null ? null : [
                'name' => $rank->name,
                'description' => $rank->description,
                'commissionPercent' => (float) $rank->commission_percent,
                'referralQuota' => $rank->referral_quota,
                'linkExpiryDays' => $rank->link_expiry_days,
                'maxActiveLinks' => $rank->max_active_links,
            ],
            'referralsUsed' => $this->referralsUsed($affiliate),
            'referralsRemaining' => $remaining,
            'canEarn' => $this->canEarn($affiliate),
            'mustUpgrade' => $this->mustUpgrade($affiliate),
            'canCreateLink' => $this->canCreateLink($affiliate),
            'nextRank' => $next === null ? null : [
                'id' => $next->id,
                'name' => $next->name,
                'description' => $next->description,
                'commissionPercent' => (float) $next->commission_percent,
                'referralQuota' => $next->referral_quota,
                'linkExpiryDays' => $next->link_expiry_days,
                'requirements' => $next->requirements->map(fn ($requirement) => [
                    'id' => $requirement->id,
                    'label' => $requirement->label,
                    'helpText' => $requirement->help_text,
                    'type' => $requirement->type,
                    'isRequired' => $requirement->is_required,
                ])->values(),
            ],
            'pendingRequest' => $affiliate->upgradeRequests()
                ->where('status', AffiliateUpgradeRequest::STATUS_PENDING)
                ->exists(),
            'lastRejection' => $affiliate->upgradeRequests()
                ->where('status', AffiliateUpgradeRequest::STATUS_REJECTED)
                ->latest('id')
                ->value('rejection_reason'),
        ];
    }
}
