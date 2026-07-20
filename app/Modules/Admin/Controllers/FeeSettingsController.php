<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\VendorFeeSetting;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin vendor posting-fee settings (docs/firstmarket_Implementation_Plan.md
 * Sprint 3): global Free/Paid posting mode and per-tier fees. Changes apply
 * only to newly submitted listings — already-submitted fee records keep the
 * amount they were created with.
 */
class FeeSettingsController extends Controller
{
    public function edit(): Response
    {
        $settings = VendorFeeSetting::current();

        return Inertia::render('Admin/Settings/Fees', [
            'settings' => [
                'postingMode' => $settings->posting_mode,
                'basicFeeNaira' => $settings->basic_fee_kobo / 100,
                'premiumFeeNaira' => $settings->premium_fee_kobo / 100,
                'featuredFeeNaira' => $settings->featured_fee_kobo / 100,
                'updatedAt' => $settings->updated_at->toDayDateTimeString(),
            ],
        ]);
    }

    public function update(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'posting_mode' => ['required', 'in:free,paid'],
            'basic_fee_naira' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'premium_fee_naira' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'featured_fee_naira' => ['required', 'numeric', 'min:0', 'max:10000000'],
        ]);

        $settings = VendorFeeSetting::current();
        $old = $settings->only(['posting_mode', 'basic_fee_kobo', 'premium_fee_kobo', 'featured_fee_kobo']);

        $settings->fill([
            'posting_mode' => $validated['posting_mode'],
            'basic_fee_kobo' => (int) round((float) $validated['basic_fee_naira'] * 100),
            'premium_fee_kobo' => (int) round((float) $validated['premium_fee_naira'] * 100),
            'featured_fee_kobo' => (int) round((float) $validated['featured_fee_naira'] * 100),
            'updated_by' => $request->user()->id,
        ])->save();

        $auditLogger->log(
            actor: $request->user(),
            subject: $settings,
            action: 'admin.vendor_fees_updated',
            oldValues: $old,
            newValues: $settings->only(['posting_mode', 'basic_fee_kobo', 'premium_fee_kobo', 'featured_fee_kobo']),
        );

        return redirect()->route('admin.settings.fees')->with('status', 'fees-updated');
    }
}
