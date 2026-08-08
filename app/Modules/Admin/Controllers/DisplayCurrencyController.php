<?php

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Support\StarterTemplates;
use App\Modules\Catalog\Models\DisplayCurrency;
use App\Modules\Catalog\Services\LocalePreference;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff control over the currencies shoppers can browse in, and the rate each
 * one converts at.
 *
 * These figures are shown to customers, so the screen leads with how long ago
 * each rate was touched: a rate nobody has reviewed in months is the failure
 * mode worth designing against. Nothing here changes what anyone is charged —
 * Paystack settles in naira and the naira total is always on the pay button.
 */
class DisplayCurrencyController
{
    public function index(): Response
    {
        return Inertia::render('Admin/Settings/Currencies', [
            'currencies' => DisplayCurrency::query()
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get()
                ->map(fn (DisplayCurrency $c) => [
                    'id' => $c->id,
                    'code' => $c->code,
                    'symbol' => $c->symbol,
                    'name' => $c->name,
                    'unitsPerNaira' => (float) $c->units_per_naira,
                    // The inverse is how people actually think about it:
                    // "₦1,540 to the dollar", not "0.00065 dollars per naira".
                    'nairaPerUnit' => $c->rate() > 0 ? round(1 / $c->rate(), 4) : null,
                    'decimals' => $c->decimals,
                    'isActive' => $c->is_active,
                    'isBase' => $c->isBase(),
                    'sortOrder' => $c->sort_order,
                    'updatedAt' => $c->updated_at?->diffForHumans(),
                    'updatedAgoDays' => (int) ($c->updated_at?->diffInDays() ?? 0),
                ])
                ->all(),
            'templates' => StarterTemplates::forDisplay(StarterTemplates::currencies()),
        ]);
    }

    /**
     * Lay down a ready-made set of currencies.
     *
     * The rates are placeholders and will be stale — the screen says so. What
     * the template saves is the fiddly part: the code, symbol and how many
     * decimal places each currency is conventionally written to.
     */
    public function applyTemplate(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $templates = StarterTemplates::currencies();

        $validated = $request->validate([
            'template' => ['required', 'string', Rule::in(array_keys($templates))],
        ]);

        $added = 0;

        foreach ($templates[$validated['template']]['rows'] as $row) {
            if (DisplayCurrency::query()->where('code', $row['code'])->exists()) {
                continue;
            }

            DisplayCurrency::query()->create($row + [
                'is_active' => true,
                'updated_by' => $request->user()->id,
            ]);
            $added++;
        }

        $auditLogger->log(
            actor: $request->user(),
            subject: $request->user(),
            action: 'admin.currencies_template_applied',
            newValues: ['template' => $validated['template'], 'added' => $added],
        );

        return back()->with(
            $added > 0 ? 'success' : 'error',
            $added > 0
                ? $added.' currenc'.($added === 1 ? 'y' : 'ies').' added. Check the rates before shoppers see them.'
                : 'Nothing added — those currencies are already listed.',
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DisplayCurrency::query()->create([...$data, 'updated_by' => $request->user()->id]);

        LocalePreference::forgetCurrencyCache();

        return back()->with('success', "{$data['code']} added.");
    }

    public function update(Request $request, DisplayCurrency $displayCurrency): RedirectResponse
    {
        $data = $this->validated($request, $displayCurrency);

        // The naira is what everything is priced and settled in — a rate other
        // than 1, or switching it off, would silently corrupt every price on
        // the storefront.
        if ($displayCurrency->isBase()) {
            $data['units_per_naira'] = '1';
            $data['is_active'] = true;
            $data['code'] = 'NGN';
        }

        $displayCurrency->update([...$data, 'updated_by' => $request->user()->id]);

        LocalePreference::forgetCurrencyCache();

        return back()->with('success', "{$displayCurrency->code} updated.");
    }

    public function destroy(DisplayCurrency $displayCurrency): RedirectResponse
    {
        if ($displayCurrency->isBase()) {
            return back()->with('error', 'The naira cannot be removed — every price is stored in it.');
        }

        $code = $displayCurrency->code;
        $displayCurrency->delete();

        LocalePreference::forgetCurrencyCache();

        // Shoppers whose cookie names this currency fall back to naira on their
        // next request (see LocalePreference::currency), so nothing breaks.
        return back()->with('success', "{$code} removed.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?DisplayCurrency $existing = null): array
    {
        $unique = 'unique:display_currencies,code'.($existing ? ",{$existing->id}" : '');

        return Validator::make($request->all(), [
            'code' => ['required', 'string', 'size:3', 'alpha', $unique],
            'symbol' => ['required', 'string', 'max:8'],
            'name' => ['required', 'string', 'max:60'],
            'units_per_naira' => ['required', 'numeric', 'gt:0'],
            'decimals' => ['required', 'integer', 'min:0', 'max:4'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:999'],
        ], [
            'units_per_naira.gt' => 'The rate must be greater than zero.',
            'code.unique' => 'That currency is already listed.',
        ])->validate() + ['is_active' => $request->boolean('is_active')];
    }
}
