<?php

namespace App\Modules\Affiliates\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliateCommission;
use App\Modules\Affiliates\Models\AffiliateConversion;
use App\Modules\Affiliates\Models\AffiliateLink;
use App\Modules\Affiliates\Models\AffiliatePayoutItem;
use App\Modules\Affiliates\Services\AffiliatePayoutService;
use App\Modules\Affiliates\Services\AffiliateService;
use App\Modules\Affiliates\Services\AffiliateTierResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AffiliateController extends Controller
{
    public function index(
        Request $request,
        AffiliateService $affiliates,
        AffiliateTierResolver $tiers,
        AffiliatePayoutService $payouts,
    ): Response {
        $affiliate = Affiliate::query()
            ->with([
                // Counted in the same query rather than two per link.
                'links' => fn ($query) => $query->withCount(['clicks', 'attributions']),
                'tier',
                'bankAccounts' => fn ($query) => $query->where('is_active', true),
            ])
            ->where('user_id', $request->user()->id)
            ->first();

        if ($affiliate === null) {
            return Inertia::render('Account/Affiliate', [
                'application' => null,
                'links' => [],
                'stats' => null,
                'funnel' => null,
                'payouts' => [],
                'bankAccount' => null,
                'tier' => null,
                'minimumPayoutKobo' => $payouts->minimumThresholdKobo(),
                'attributionWindowDays' => $affiliates->attributionWindowDays(),
            ]);
        }

        $linkIds = $affiliate->links->pluck('id');
        $tier = $tiers->effectiveTier($affiliate);
        $bankAccount = $affiliate->bankAccounts->first();

        $countByType = fn (string $type) => $affiliate->conversions()
            ->where('conversion_type', $type)
            ->whereIn('status', [AffiliateConversion::STATUS_QUALIFIED, AffiliateConversion::STATUS_REVIEW])
            ->count();

        return Inertia::render('Account/Affiliate', [
            'application' => [
                'displayName' => $affiliate->display_name,
                'status' => $affiliate->status,
                'rejectionReason' => $affiliate->rejection_reason,
                'suspensionReason' => $affiliate->suspension_reason,
            ],
            'links' => $affiliate->links->map(fn (AffiliateLink $link) => [
                'id' => $link->id,
                'label' => $link->label,
                'campaign' => $link->campaign,
                'status' => $link->status,
                'expiresAt' => $link->expires_at?->toDateString(),
                'url' => $this->linkUrl($link),
                'clicks' => (int) $link->clicks_count,
                'signups' => (int) $link->attributions_count,
            ])->values(),
            'stats' => [
                'clicks' => \App\Modules\Affiliates\Models\AffiliateClick::query()->whereIn('affiliate_link_id', $linkIds)->count(),
                'signups' => \App\Modules\Affiliates\Models\AffiliateAttribution::query()->whereIn('affiliate_link_id', $linkIds)->count(),
                'conversions' => $affiliate->conversions()->where('status', AffiliateConversion::STATUS_QUALIFIED)->count(),
                'inReview' => $affiliate->conversions()->where('status', AffiliateConversion::STATUS_REVIEW)->count(),
                // Earned but not yet in an approved batch.
                'pendingKobo' => (int) $payouts->payableCommissions($affiliate)->sum('amount_kobo'),
                'paidKobo' => (int) $affiliate->commissions()->where('status', AffiliateCommission::STATUS_PAID)->sum('amount_kobo'),
            ],
            // The funnel a partner is actually judged on, so "why am I on this
            // tier" is answerable without asking support.
            'funnel' => [
                'signups' => $countByType(AffiliateConversion::TYPE_SIGNUP),
                'verified' => $countByType(AffiliateConversion::TYPE_VERIFIED_USER),
                'deliveredOrders' => $countByType(AffiliateConversion::TYPE_DELIVERED_ORDER),
                'completedPlanOrders' => $countByType(AffiliateConversion::TYPE_COMPLETED_PLAN_ORDER),
                'vendorsRecruited' => $countByType(AffiliateConversion::TYPE_VENDOR_PRODUCT),
            ],
            'payouts' => AffiliatePayoutItem::query()
                ->with('batch:id,uuid,period_start,period_end')
                ->where('affiliate_id', $affiliate->id)
                ->latest('id')
                ->limit(24)
                ->get()
                ->map(fn (AffiliatePayoutItem $item) => [
                    'id' => $item->id,
                    'amountKobo' => $item->amount_kobo,
                    'status' => $item->status->value,
                    'rejectionReason' => $item->rejection_reason,
                    'failureReason' => $item->failure_reason,
                    'paidAt' => $item->paid_at?->toDateString(),
                    'period' => $item->batch?->period_start?->toDateString().' – '.$item->batch?->period_end?->toDateString(),
                ])->values(),
            'bankAccount' => $bankAccount ? [
                'bankName' => $bankAccount->bank_name,
                'accountName' => $bankAccount->account_name,
                'maskedNumber' => $bankAccount->maskedNumber(),
                'verified' => $bankAccount->verified_at !== null,
            ] : null,
            'tier' => $tier ? [
                'name' => $tier->name,
                'description' => $tier->description,
                'commissionType' => $tier->commission_type,
                'commissionPercent' => (float) $tier->commission_percent,
                'flatAmountKobo' => $tier->flat_amount_kobo,
                'vendorRecruitmentKobo' => $tier->vendor_recruitment_kobo,
            ] : null,
            'minimumPayoutKobo' => $payouts->minimumThresholdKobo(),
            'attributionWindowDays' => $affiliates->attributionWindowDays(),
        ]);
    }

    public function apply(Request $request, AffiliateService $affiliates): RedirectResponse
    {
        $validated = $request->validate(['display_name' => ['required', 'string', 'max:120']]);
        $affiliates->apply($request->user(), $validated['display_name']);

        return back()->with('success', 'Affiliate application submitted for review.');
    }

    public function storeLink(Request $request, AffiliateService $affiliates): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'campaign' => ['nullable', 'string', 'max:80'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $affiliate = $this->ownAffiliate($request);
        $affiliates->createLink(
            $affiliate,
            $validated['label'],
            $validated['campaign'] ?? null,
            isset($validated['expires_at']) ? new \DateTimeImmutable($validated['expires_at']) : null,
        );

        return back()->with('success', 'Campaign link created.');
    }

    public function destroyLink(Request $request, AffiliateLink $link): RedirectResponse
    {
        $affiliate = $this->ownAffiliate($request);
        abort_unless($link->affiliate_id === $affiliate->id, 403);

        // Switched off rather than deleted: the clicks and attributions behind
        // it are the evidence for commissions already earned.
        $link->forceFill(['status' => AffiliateLink::STATUS_SUSPENDED])->save();

        return back()->with('success', 'Link switched off. Anyone who already signed up through it still counts.');
    }

    public function storeBankAccount(Request $request, AffiliatePayoutService $payouts): RedirectResponse
    {
        $validated = $request->validate([
            'bank_name' => ['required', 'string', 'max:100'],
            'bank_code' => ['nullable', 'string', 'max:10'],
            'account_number' => ['required', 'string', 'min:10', 'max:20'],
            'account_name' => ['required', 'string', 'max:120'],
        ]);

        $payouts->addBankAccount($this->ownAffiliate($request), $validated);

        return back()->with('success', 'Payout account saved. It has to be verified by our team before a payout can be sent.');
    }

    public function capture(Request $request, string $code, AffiliateService $affiliates): RedirectResponse
    {
        $link = $affiliates->capture(
            $code,
            $request->ip(),
            (string) $request->userAgent(),
            $request->query('s') !== null ? (string) $request->query('s') : null,
        );

        if ($link !== null) {
            $request->session()->put('affiliate_link_id', $link->id);
        }

        /*
         * Always our own home route, never a destination read off the query
         * string. A partner link that forwarded anywhere would be an open
         * redirect wearing the marketplace's domain — exactly the thing a
         * phishing campaign wants.
         */
        return redirect()->route('home', ['auth' => 'register']);
    }

    private function linkUrl(AffiliateLink $link): string
    {
        return $link->signature === null
            ? route('affiliates.capture', $link->code)
            : route('affiliates.capture', ['code' => $link->code, 's' => $link->signature]);
    }

    private function ownAffiliate(Request $request): Affiliate
    {
        $affiliate = Affiliate::query()->where('user_id', $request->user()->id)->first();

        if ($affiliate === null) {
            throw ValidationException::withMessages(['affiliate' => 'You are not registered as an affiliate.']);
        }

        return $affiliate;
    }
}
