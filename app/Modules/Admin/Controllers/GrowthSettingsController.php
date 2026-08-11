<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Modules\Rewards\Models\RewardTier;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GrowthSettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Admin/Settings/Growth', [
            'affiliateCommissionPercent' => (float) Setting::get('affiliates.commission_percent', 5),
            'affiliateClickDedupeHours' => (int) Setting::get('affiliates.click_dedupe_hours', 24),
            'referralRewardNaira' => (int) Setting::get('referrals.reward_amount_kobo', 50_000) / 100,
            'tiers' => RewardTier::query()->orderBy('sort_order')->orderBy('minimum_completed_savings')->get()->map(fn (RewardTier $tier) => [
                'id' => $tier->id,
                'name' => $tier->name,
                'minimumCompletedSavingsNaira' => $tier->minimum_completed_savings / 100,
                // Always a list, whatever shape is in the column: the page
                // renders these as lines of text and cannot recover from an
                // object arriving where it expects an array.
                'benefits' => self::benefitList($tier->benefits),
                'status' => $tier->status,
                'sortOrder' => $tier->sort_order,
            ]),
        ]);
    }

    public function update(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'affiliate_commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'affiliate_click_dedupe_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'referral_reward_naira' => ['required', 'numeric', 'min:0', 'max:10000000'],
        ]);

        Setting::set('affiliates.commission_percent', (float) $validated['affiliate_commission_percent'], 'growth');
        Setting::set('affiliates.click_dedupe_hours', (int) $validated['affiliate_click_dedupe_hours'], 'growth');
        Setting::set('referrals.reward_amount_kobo', (int) round((float) $validated['referral_reward_naira'] * 100), 'growth');

        $auditLogger->log(
            actor: $request->user(),
            subject: Setting::query()->where('key', 'affiliates.commission_percent')->firstOrFail(),
            action: 'admin.growth_settings_updated',
            newValues: $validated,
        );

        return back()->with('success', 'Growth settings updated. Existing referrals and commissions keep their snapshots.');
    }

    public function updateTier(Request $request, RewardTier $rewardTier, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'minimum_completed_savings_naira' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'benefits' => ['nullable', 'array'],
            'benefits.*' => ['string', 'max:120'],
            'status' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);

        $rewardTier->update([
            'name' => $validated['name'],
            'minimum_completed_savings' => (int) round((float) $validated['minimum_completed_savings_naira'] * 100),
            'benefits' => self::benefitList($validated['benefits'] ?? []),
            'status' => $validated['status'],
            'sort_order' => $validated['sort_order'],
        ]);

        $auditLogger->log(actor: $request->user(), subject: $rewardTier, action: 'admin.reward_tier_updated', newValues: $validated);

        return back()->with('success', 'Reward tier updated.');
    }

    /**
     * Coerce a benefits value of any shape into the list of strings the admin
     * screen edits. Keyed arrays lose their keys rather than their values.
     *
     * @param  mixed  $benefits
     * @return list<string>
     */
    private static function benefitList($benefits): array
    {
        return array_values(array_map(
            static fn ($benefit): string => trim((string) $benefit),
            array_filter(is_array($benefits) ? $benefits : [], 'is_scalar'),
        ));
    }
}
