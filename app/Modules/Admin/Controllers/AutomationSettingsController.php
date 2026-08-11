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
 * The thresholds behind the automated parts of the system.
 *
 * These were all read from `Setting::get(...)` with a sensible fallback, which
 * made them look configurable while there was nowhere to configure them: the
 * only way to change one was to edit the database by hand. A default that
 * cannot be changed is a hardcoded value with extra steps.
 *
 * The schema below is the single source for the form, the read, the write and
 * the validation. Adding a threshold in one place is deliberate — the previous
 * arrangement, where a service knew a key and the admin screen did not, is
 * exactly how they drifted apart.
 */
class AutomationSettingsController extends Controller
{
    /**
     * group => [key => [label, help, min, max, default]]
     *
     * Defaults mirror the fallbacks in the services that read them, so this
     * screen shows the truth on a fresh install rather than a blank box.
     *
     * @var array<string, array<string, array{label: string, help: string, min: int, max: int, default: int}>>
     */
    private const SCHEMA = [
        'Orders' => [
            'orders.auto_confirm_days' => [
                'label' => 'Auto-confirm delivery after',
                'help' => 'Days. A delivered order confirms itself if the customer says nothing.',
                'min' => 1, 'max' => 30, 'default' => 3,
            ],
            'orders.prepare_sla_hours' => [
                'label' => 'Vendor packing deadline',
                'help' => 'Hours from a confirmed order until the vendor is marked late.',
                'min' => 1, 'max' => 336, 'default' => 48,
            ],
        ],
        'Plans and delivery' => [
            'savings.max_plan_switches' => [
                'label' => 'Plan switches allowed',
                'help' => 'How many times a customer may move a plan to a different item.',
                'min' => 0, 'max' => 20, 'default' => 2,
            ],
            'logistics.max_delivery_attempts' => [
                'label' => 'Delivery attempts',
                'help' => 'Tries before a parcel goes back to the office to be sorted out.',
                'min' => 1, 'max' => 10, 'default' => 3,
            ],
        ],
        'Home page' => [
            'home.featured_limit' => [
                'label' => 'Featured products shown',
                'help' => 'Tiles in the featured sections.',
                'min' => 1, 'max' => 48, 'default' => 8,
            ],
            'home.newest_limit' => [
                'label' => 'New arrivals shown',
                'help' => 'Tiles in the newest section.',
                'min' => 1, 'max' => 48, 'default' => 12,
            ],
        ],
        'Vendor rating weights' => [
            'vendor_rating.weight_fulfilment' => [
                'label' => 'Getting orders delivered',
                'help' => 'Share of the 100-point score.',
                'min' => 0, 'max' => 100, 'default' => 40,
            ],
            'vendor_rating.weight_rejection' => [
                'label' => 'Not rejecting orders',
                'help' => 'Share of the score.',
                'min' => 0, 'max' => 100, 'default' => 25,
            ],
            'vendor_rating.weight_returns' => [
                'label' => 'Items not sent back',
                'help' => 'Share of the score.',
                'min' => 0, 'max' => 100, 'default' => 15,
            ],
            'vendor_rating.weight_punctuality' => [
                'label' => 'Packing on time',
                'help' => 'Share of the score.',
                'min' => 0, 'max' => 100, 'default' => 10,
            ],
            'vendor_rating.weight_reviews' => [
                'label' => 'Customer ratings',
                'help' => 'Share of the score.',
                'min' => 0, 'max' => 100, 'default' => 10,
            ],
            'vendor_rating.minimum_orders_to_rate' => [
                'label' => 'Orders before a vendor is judged',
                'help' => 'Below this, a vendor scores a neutral 50 rather than being marked down for being new.',
                'min' => 1, 'max' => 500, 'default' => 5,
            ],
        ],
        'Risk flags' => [
            'risk.failed_payments_threshold' => [
                'label' => 'Failed payments before flagging',
                'help' => 'Within the window below.',
                'min' => 1, 'max' => 50, 'default' => 3,
            ],
            'risk.failed_payments_window_days' => [
                'label' => 'Failed payment window',
                'help' => 'Days.',
                'min' => 1, 'max' => 90, 'default' => 7,
            ],
            'risk.plan_switches_threshold' => [
                'label' => 'Plan switches before flagging',
                'help' => 'How often a plan may be moved between products.',
                'min' => 1, 'max' => 20, 'default' => 3,
            ],
            'risk.plan_switches_window_days' => [
                'label' => 'Plan switch window',
                'help' => 'Days.',
                'min' => 1, 'max' => 365, 'default' => 30,
            ],
            'risk.vendor_rejection_percent' => [
                'label' => 'Vendor rejection rate',
                'help' => 'Percent of orders rejected before a vendor is flagged.',
                'min' => 1, 'max' => 100, 'default' => 25,
            ],
            'risk.vendor_rejection_minimum_orders' => [
                'label' => 'Orders before judging rejections',
                'help' => 'Too few orders and the percentage is noise.',
                'min' => 1, 'max' => 500, 'default' => 8,
            ],
            'risk.vendor_return_percent' => [
                'label' => 'Vendor return rate',
                'help' => 'Percent of delivered orders returned before flagging.',
                'min' => 1, 'max' => 100, 'default' => 20,
            ],
            'risk.vendor_return_minimum_orders' => [
                'label' => 'Deliveries before judging returns',
                'help' => 'Too few deliveries and the percentage is noise.',
                'min' => 1, 'max' => 500, 'default' => 8,
            ],
        ],
        'Savings assistant' => [
            'assistant.history_payments' => [
                'label' => 'Payments to look back on',
                'help' => 'How much history is used to work out a customer’s usual amount.',
                'min' => 2, 'max' => 50, 'default' => 6,
            ],
            'assistant.minimum_payments' => [
                'label' => 'Payments before advising',
                'help' => 'Below this the assistant says it does not know yet rather than guessing.',
                'min' => 1, 'max' => 20, 'default' => 3,
            ],
            'assistant.behind_tolerance_percent' => [
                'label' => 'Tolerance before "behind"',
                'help' => 'Percent of one instalment a plan may slip before it is called behind.',
                'min' => 0, 'max' => 1000, 'default' => 50,
            ],
        ],
        'Recommendations' => [
            'recommendations.limit' => [
                'label' => 'Suggestions shown',
                'help' => 'How many products to recommend at a time.',
                'min' => 1, 'max' => 24, 'default' => 8,
            ],
            'recommendations.price_band_percent' => [
                'label' => 'Price band',
                'help' => 'Percent either side of what the customer usually spends.',
                'min' => 10, 'max' => 500, 'default' => 60,
            ],
        ],
    ];

    public function edit(): Response
    {
        $groups = [];

        foreach (self::SCHEMA as $group => $fields) {
            $rendered = [];

            foreach ($fields as $key => $meta) {
                $rendered[] = [
                    'key' => $key,
                    'name' => $this->fieldName($key),
                    'label' => $meta['label'],
                    'help' => $meta['help'],
                    'min' => $meta['min'],
                    'max' => $meta['max'],
                    'value' => (int) Setting::get($key, $meta['default']),
                    'default' => $meta['default'],
                ];
            }

            $groups[] = ['title' => $group, 'fields' => $rendered];
        }

        return Inertia::render('Admin/Settings/Automation', ['groups' => $groups]);
    }

    public function update(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $rules = [];
        $keyByField = [];

        foreach (self::SCHEMA as $fields) {
            foreach ($fields as $key => $meta) {
                $field = $this->fieldName($key);
                $rules[$field] = ['required', 'integer', 'min:'.$meta['min'], 'max:'.$meta['max']];
                $keyByField[$field] = $key;
            }
        }

        $validated = $request->validate($rules);

        foreach ($validated as $field => $value) {
            $key = $keyByField[$field];
            Setting::set($key, (int) $value, explode('.', $key)[0]);
        }

        $auditLogger->log(
            actor: $request->user(),
            subject: Setting::query()->where('key', 'orders.auto_confirm_days')->firstOrFail(),
            action: 'admin.automation_settings_updated',
            newValues: $validated,
        );

        return back()->with('success', 'Saved. New thresholds apply from the next run.');
    }

    /** `risk.vendor_return_percent` → `risk_vendor_return_percent`. */
    private function fieldName(string $key): string
    {
        return str_replace('.', '_', $key);
    }
}
