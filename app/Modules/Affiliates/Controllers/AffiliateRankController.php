<?php

namespace App\Modules\Affiliates\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Affiliates\Models\AffiliateRankRequirement;
use App\Modules\Affiliates\Models\AffiliateTier;
use App\Modules\Affiliates\Models\AffiliateUpgradeRequest;
use App\Modules\Affiliates\Services\AffiliateRankService;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The rank ladder, as staff manage it.
 *
 * Every rung is data — its quota, its link lifetime, its rate, and the
 * documents it asks for — so adding "Elite" next year is a form, not a
 * deploy. That was the whole point of building it this way.
 */
class AffiliateRankController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Affiliates/Ranks', [
            'ranks' => AffiliateTier::query()
                ->with('requirements')
                ->withCount('affiliates')
                ->orderBy('sort_order')
                ->get()
                ->map(fn (AffiliateTier $rank) => [
                    'id' => $rank->id,
                    'name' => $rank->name,
                    'description' => $rank->description,
                    'commissionPercent' => (float) $rank->commission_percent,
                    'vendorRecruitmentKobo' => $rank->vendor_recruitment_kobo,
                    'referralQuota' => $rank->referral_quota,
                    'linkExpiryDays' => $rank->link_expiry_days,
                    'maxActiveLinks' => $rank->max_active_links,
                    'requiresApproval' => $rank->requires_approval,
                    'minDeliveredConversions' => $rank->min_delivered_conversions,
                    'isDefault' => $rank->is_default,
                    'isActive' => $rank->is_active,
                    'sortOrder' => $rank->sort_order,
                    'partnerCount' => $rank->affiliates_count,
                    'requirements' => $rank->requirements->map(fn (AffiliateRankRequirement $requirement) => [
                        'id' => $requirement->id,
                        'label' => $requirement->label,
                        'helpText' => $requirement->help_text,
                        'type' => $requirement->type,
                        'isRequired' => $requirement->is_required,
                    ])->values(),
                ]),

            // Partners waiting to be moved up, with what they submitted.
            'upgradeRequests' => AffiliateUpgradeRequest::query()
                ->with(['affiliate:id,display_name', 'fromTier:id,name', 'toTier:id,name', 'answers.requirement', 'answers.document'])
                ->where('status', AffiliateUpgradeRequest::STATUS_PENDING)
                ->latest('id')
                ->get()
                ->map(fn (AffiliateUpgradeRequest $request) => [
                    'uuid' => $request->uuid,
                    'affiliate' => $request->affiliate?->display_name,
                    'from' => $request->fromTier?->name,
                    'to' => $request->toTier?->name,
                    'submittedAt' => $request->created_at?->diffForHumans(),
                    'answers' => $request->answers->map(fn ($answer) => [
                        'label' => $answer->requirement?->label,
                        'type' => $answer->requirement?->type,
                        'value' => $answer->value,
                        'documentUuid' => $answer->document?->uuid,
                    ])->values(),
                ])->values(),
        ]);
    }

    public function store(Request $request, AuditLoggerContract $audit): RedirectResponse
    {
        $rank = AffiliateTier::query()->create($this->validated($request));

        $audit->log(actor: $request->user(), subject: $rank, action: 'affiliate.rank_created', newValues: ['name' => $rank->name]);

        return back()->with('success', "Rank “{$rank->name}” added.");
    }

    public function update(Request $request, AffiliateTier $rank, AuditLoggerContract $audit): RedirectResponse
    {
        $before = $rank->only(['name', 'commission_percent', 'referral_quota', 'link_expiry_days']);

        $rank->update($this->validated($request, $rank));

        $audit->log(
            actor: $request->user(),
            subject: $rank,
            action: 'affiliate.rank_updated',
            oldValues: $before,
            newValues: $rank->only(['name', 'commission_percent', 'referral_quota', 'link_expiry_days']),
        );

        return back()->with('success', 'Rank updated. Partners already on it keep their current allowance.');
    }

    /**
     * Ranks are switched off rather than deleted.
     *
     * Partners sit on them and commissions were priced by them, so removing
     * one would orphan both. A retired rank simply stops being somewhere new
     * partners can arrive.
     */
    public function destroy(Request $request, AffiliateTier $rank, AuditLoggerContract $audit): RedirectResponse
    {
        if ($rank->is_default) {
            return back()->with('error', 'The starting rank cannot be retired — every new partner needs somewhere to land.');
        }

        $rank->forceFill(['is_active' => false])->save();

        $audit->log(actor: $request->user(), subject: $rank, action: 'affiliate.rank_retired');

        return back()->with('success', "“{$rank->name}” retired. Partners on it keep their rate until they are moved.");
    }

    // ── Requirements ────────────────────────────────────────────────────────

    public function storeRequirement(Request $request, AffiliateTier $rank): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'help_text' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in([
                AffiliateRankRequirement::TYPE_DOCUMENT,
                AffiliateRankRequirement::TYPE_TEXT,
                AffiliateRankRequirement::TYPE_NUMBER,
            ])],
            'is_required' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:999'],
        ]);

        $rank->requirements()->create($validated + ['is_required' => $validated['is_required'] ?? true]);

        return back()->with('success', 'Requirement added. Partners applying for this rank will be asked for it.');
    }

    public function destroyRequirement(AffiliateRankRequirement $requirement): RedirectResponse
    {
        $requirement->delete();

        return back()->with('success', 'Requirement removed.');
    }

    // ── Upgrade review ──────────────────────────────────────────────────────

    public function approveUpgrade(Request $request, AffiliateUpgradeRequest $upgrade, AffiliateRankService $ranks): RedirectResponse
    {
        $ranks->approveUpgrade($request->user(), $upgrade);

        return back()->with('success', "{$upgrade->affiliate?->display_name} moved up to {$upgrade->toTier?->name}.");
    }

    public function rejectUpgrade(Request $request, AffiliateUpgradeRequest $upgrade, AffiliateRankService $ranks): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $ranks->rejectUpgrade($request->user(), $upgrade, $validated['reason']);

        return back()->with('success', 'Application rejected. The partner can see why and apply again.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?AffiliateTier $existing = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('affiliate_tiers', 'name')->ignore($existing?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'vendor_recruitment_kobo' => ['integer', 'min:0'],
            // Zero means unlimited on all three, which is what the top of the
            // ladder carries.
            'referral_quota' => ['integer', 'min:0', 'max:100000'],
            'link_expiry_days' => ['integer', 'min:0', 'max:3650'],
            'max_active_links' => ['integer', 'min:0', 'max:1000'],
            'requires_approval' => ['boolean'],
            'min_delivered_conversions' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
            'sort_order' => ['required', 'integer', 'min:1', 'max:999'],
        ]);
    }
}
