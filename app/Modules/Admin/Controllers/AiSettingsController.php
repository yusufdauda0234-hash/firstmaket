<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super Administrator AI settings (Sprint 9,
 * docs/FirstMaket_Implementation_Plan.md): today this is just the
 * price-outlier threshold RuleBasedListingAnalyzer reads via
 * Setting::get('ai.price_outlier_threshold_percent'). Follows the same
 * Setting key/value pattern as CommissionSettingsController/FeeSettingsController
 * rather than a dedicated table.
 */
class AiSettingsController extends Controller
{
    private const DEFAULT_THRESHOLD_PERCENT = 60;

    public function edit(): Response
    {
        return Inertia::render('Admin/Settings/Ai', [
            'priceOutlierThresholdPercent' => (float) Setting::get(
                'ai.price_outlier_threshold_percent',
                self::DEFAULT_THRESHOLD_PERCENT,
            ),
        ]);
    }

    public function update(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'price_outlier_threshold_percent' => ['required', 'numeric', 'min:5', 'max:500'],
        ]);

        Setting::set(
            'ai.price_outlier_threshold_percent',
            (float) $validated['price_outlier_threshold_percent'],
            'ai',
        );

        $auditLogger->log(
            actor: $request->user(),
            subject: Setting::query()->where('key', 'ai.price_outlier_threshold_percent')->firstOrFail(),
            action: 'admin.ai_settings_updated',
            newValues: $validated,
        );

        return back()->with('success', 'AI settings updated.');
    }
}
