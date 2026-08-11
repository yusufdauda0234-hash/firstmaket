<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Modules\Payments\Models\AutomaticDebit;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The numbers that govern returns, pausing a plan, and automatic debit.
 *
 * These began life in config/firstmaket.php, which meant changing a returns
 * window needed a developer and a deploy. They are settings now, and the
 * config values remain as the fallback a fresh install runs on.
 *
 * The returns window in particular is worth the care: it is printed verbatim
 * on every product page and enforced by ReturnPolicy, and both read this one
 * value — so editing it here changes the promise and the enforcement together,
 * which is the only way they stay honest.
 */
class OperationsSettingsController extends Controller
{
    /** Key => config fallback, in one place so read and write cannot drift. */
    private const KEYS = [
        'returns.window_days' => ['config' => 'firstmaket.returns.window_days', 'default' => 7],
        'returns.refund_days_min' => ['config' => 'firstmaket.returns.refund_days_min', 'default' => 5],
        'returns.refund_days_max' => ['config' => 'firstmaket.returns.refund_days_max', 'default' => 10],
        'savings.max_pause_days' => ['config' => 'firstmaket.savings.max_pause_days', 'default' => 60],
        'automatic_debit.retry_after_hours' => ['config' => null, 'default' => AutomaticDebit::RETRY_AFTER_HOURS],
        'automatic_debit.max_failures' => ['config' => null, 'default' => AutomaticDebit::MAX_FAILURES],
    ];

    public function edit(): Response
    {
        $values = [];

        foreach (self::KEYS as $key => $meta) {
            $fallback = $meta['config'] !== null ? config($meta['config'], $meta['default']) : $meta['default'];
            $values[$key] = (int) Setting::get($key, $fallback);
        }

        return Inertia::render('Admin/Settings/Operations', [
            'settings' => [
                'returnWindowDays' => $values['returns.window_days'],
                'refundDaysMin' => $values['returns.refund_days_min'],
                'refundDaysMax' => $values['returns.refund_days_max'],
                'maxPauseDays' => $values['savings.max_pause_days'],
                'debitRetryAfterHours' => $values['automatic_debit.retry_after_hours'],
                'debitMaxFailures' => $values['automatic_debit.max_failures'],
            ],
        ]);
    }

    public function update(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            // Ceilings are not arbitrary: a returns window of a year is almost
            // certainly a typo, and the damage from one is real money.
            'return_window_days' => ['required', 'integer', 'min:1', 'max:90'],
            'refund_days_min' => ['required', 'integer', 'min:1', 'max:60'],
            'refund_days_max' => ['required', 'integer', 'min:1', 'max:90', 'gte:refund_days_min'],
            'max_pause_days' => ['required', 'integer', 'min:1', 'max:365'],
            'debit_retry_after_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'debit_max_failures' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        Setting::set('returns.window_days', (int) $validated['return_window_days'], 'operations');
        Setting::set('returns.refund_days_min', (int) $validated['refund_days_min'], 'operations');
        Setting::set('returns.refund_days_max', (int) $validated['refund_days_max'], 'operations');
        Setting::set('savings.max_pause_days', (int) $validated['max_pause_days'], 'operations');
        Setting::set('automatic_debit.retry_after_hours', (int) $validated['debit_retry_after_hours'], 'operations');
        Setting::set('automatic_debit.max_failures', (int) $validated['debit_max_failures'], 'operations');

        $auditLogger->log(
            actor: $request->user(),
            subject: Setting::query()->where('key', 'returns.window_days')->firstOrFail(),
            action: 'admin.operations_settings_updated',
            newValues: $validated,
        );

        return back()->with(
            'success',
            'Saved. Returns already open keep the terms they were opened under.',
        );
    }
}
