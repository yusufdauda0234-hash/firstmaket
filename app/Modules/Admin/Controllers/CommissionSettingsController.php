<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\CommissionRule;
use App\Modules\Orders\Services\CommissionRate;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Commission rules: what FirstMaket takes, on what, and between which prices.
 *
 * A single percentage per category cannot price a catalogue honestly — two
 * items in one category at ₦500 and ₦5,000 cost the same to process but earn
 * ten times apart. So a rule carries a scope, a price band, and optional
 * floors and ceilings, and the most specific match wins.
 *
 * Rules are configuration, not history: orders snapshot their commission at
 * creation, so editing or deleting a rule never disturbs money already owed.
 */
class CommissionSettingsController extends Controller
{
    public function edit(): Response
    {
        $rules = CommissionRule::query()
            ->with('updatedBy:id,name')
            ->get()
            // Most specific first, mirroring how CommissionRate resolves them,
            // so the list reads in the order they actually apply.
            ->sortByDesc(fn (CommissionRule $rule) => [$rule->specificity(), -$rule->min_price_kobo])
            ->values()
            ->map(fn (CommissionRule $rule) => [
                'uuid' => $rule->uuid,
                'scopeType' => $rule->scope_type,
                'scopeId' => $rule->scope_id,
                'scopeLabel' => $rule->scopeLabel(),
                'minPriceNaira' => $rule->min_price_kobo / 100,
                'maxPriceNaira' => $rule->max_price_kobo === null ? null : $rule->max_price_kobo / 100,
                'ratePercent' => (float) $rule->rate_percent,
                'maxCommissionNaira' => $rule->max_commission_kobo === null ? null : $rule->max_commission_kobo / 100,
                'isActive' => $rule->is_active,
                'note' => $rule->note,
                'updatedBy' => $rule->updatedBy?->name,
            ]);

        return Inertia::render('Admin/Settings/Commissions', [
            'rules' => $rules,
            'defaultRatePercent' => (float) Setting::get('orders.default_commission_percent', 0),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'vendors' => VendorProfile::query()
                ->orderBy('business_name')
                ->get(['id', 'business_name'])
                ->map(fn (VendorProfile $vendor) => ['id' => $vendor->id, 'name' => $vendor->business_name]),
            // Sent whole so the form can narrow by category and vendor
            // without a round trip. Fine for a catalogue this size; past a
            // few thousand listings this wants a search endpoint like
            // savings.switch-options rather than a bigger payload.
            'products' => Product::query()
                ->approved()
                ->orderBy('name')
                ->get(['id', 'name', 'category_id', 'vendor_id', 'price_kobo'])
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'categoryId' => $product->category_id,
                    'vendorId' => $product->vendor_id,
                    'priceNaira' => $product->price_kobo / 100,
                ]),
        ]);
    }

    public function store(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $rule = CommissionRule::query()->create(
            $this->validated($request) + ['updated_by' => $request->user()->id]
        );

        $auditLogger->log(
            actor: $request->user(),
            subject: $rule,
            action: 'admin.commission_rule_created',
            newValues: $rule->only(['scope_type', 'scope_id', 'min_price_kobo', 'max_price_kobo', 'rate_percent']),
        );

        return back()->with('success', 'Rule added. It applies to orders placed from now on.');
    }

    public function update(Request $request, CommissionRule $commissionRule, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $before = $commissionRule->only(['scope_type', 'scope_id', 'min_price_kobo', 'max_price_kobo', 'rate_percent']);

        $commissionRule->update(
            $this->validated($request, $commissionRule) + ['updated_by' => $request->user()->id]
        );

        $auditLogger->log(
            actor: $request->user(),
            subject: $commissionRule,
            action: 'admin.commission_rule_updated',
            oldValues: $before,
            newValues: $commissionRule->only(['scope_type', 'scope_id', 'min_price_kobo', 'max_price_kobo', 'rate_percent']),
        );

        return back()->with('success', 'Rule updated. Orders already placed keep their own commission.');
    }

    public function destroy(Request $request, CommissionRule $commissionRule, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $auditLogger->log(actor: $request->user(), subject: $commissionRule, action: 'admin.commission_rule_deleted');

        $commissionRule->delete();

        return back()->with('success', 'Rule deleted. Sales it covered fall to the next rule that matches.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?CommissionRule $existing = null): array
    {
        $validated = $request->validate([
            'scope_type' => ['required', Rule::in(['global', 'category', 'vendor', 'product'])],
            'scope_id' => ['nullable', 'integer'],
            'min_price_naira' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'max_price_naira' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_commission_naira' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'is_active' => ['boolean'],
            'note' => ['nullable', 'string', 'max:200'],
        ], [
            'rate_percent.max' => 'A rate over 100% would take more than the sale is worth.',
        ]);

        $scopeType = $validated['scope_type'];
        $scopeId = $scopeType === 'global' ? null : ($validated['scope_id'] ?? null);

        if ($scopeType !== 'global' && $scopeId === null) {
            throw ValidationException::withMessages([
                'scope_id' => 'Choose which '.$scopeType.' this rule is for.',
            ]);
        }

        // A scope id pointing at nothing would make a rule that silently never
        // matches, which is worse than refusing it.
        $exists = match ($scopeType) {
            'category' => Category::query()->whereKey($scopeId)->exists(),
            'vendor' => VendorProfile::query()->whereKey($scopeId)->exists(),
            'product' => Product::query()->whereKey($scopeId)->exists(),
            default => true,
        };

        if (! $exists) {
            throw ValidationException::withMessages(['scope_id' => 'That '.$scopeType.' no longer exists.']);
        }

        $minKobo = (int) round(((float) ($validated['min_price_naira'] ?? 0)) * 100);
        $maxKobo = isset($validated['max_price_naira'])
            ? (int) round(((float) $validated['max_price_naira']) * 100)
            : null;

        if ($maxKobo !== null && $maxKobo <= $minKobo) {
            throw ValidationException::withMessages([
                'max_price_naira' => 'The top of the band has to be above the bottom.',
            ]);
        }

        return [
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'min_price_kobo' => $minKobo,
            'max_price_kobo' => $maxKobo,
            'rate_percent' => number_format((float) $validated['rate_percent'], 2, '.', ''),
            'max_commission_kobo' => isset($validated['max_commission_naira'])
                ? (int) round(((float) $validated['max_commission_naira']) * 100)
                : null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'note' => $validated['note'] ?? null,
        ];
    }
}
