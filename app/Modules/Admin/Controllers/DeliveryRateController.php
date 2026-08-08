<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\StarterTemplates;
use App\Modules\Orders\Models\DeliveryRate;
use App\Modules\Orders\Services\DeliveryPricing;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Nigeria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin control over what delivery costs.
 *
 * The fee is kept as its two real legs — vendor → hub, then hub → customer —
 * because that is how the cost is actually incurred and how it gets
 * renegotiated. Customers are quoted the sum.
 *
 * One row has no state: it is the default every other state falls back to,
 * so the whole country can be priced with a single row and Lagos can still
 * differ. Deleting the default is refused rather than silently dropping the
 * app back to the config fallback.
 */
class DeliveryRateController extends Controller
{
    public function index(DeliveryPricing $pricing): Response
    {
        $rates = DeliveryRate::query()
            ->with('updatedBy:id,name')
            // Default first, then alphabetically — the fallback is the row
            // staff need most often.
            ->orderByRaw('state IS NOT NULL')
            ->orderBy('state')
            ->get()
            ->map(fn (DeliveryRate $rate) => [
                // What checkout will actually use for this state, resolved
                // through the same chain. Equal to the row own threshold now
                // that nothing is inherited, but read through the pricer so
                // the screen cannot drift from the charge.
                'effectiveFreeThresholdNaira' => $pricing->freeThresholdKobo($rate->state) / 100,
                'uuid' => $rate->uuid,
                'state' => $rate->state,
                'isDefault' => $rate->isDefault(),
                'feeNaira' => $rate->fee_kobo / 100,
                'totalNaira' => $rate->totalKobo() / 100,
                'freeThresholdNaira' => $rate->free_threshold_kobo === null
                    ? null
                    : $rate->free_threshold_kobo / 100,
                'isActive' => $rate->is_active,
                'note' => $rate->note,
                'updatedBy' => $rate->updatedBy?->name,
                'updatedAt' => $rate->updated_at->format('j M Y'),
            ]);

        return Inertia::render('Admin/Settings/DeliveryRates', [
            'rates' => $rates,
            // States that do not yet have their own row, for the add form.
            'availableStates' => array_values(array_diff(
                Nigeria::STATES,
                DeliveryRate::query()->whereNotNull('state')->pluck('state')->all(),
            )),
            'hasDefault' => DeliveryRate::query()->active()->whereNull('state')->exists(),
            'templates' => StarterTemplates::forDisplay(StarterTemplates::deliveryRates()),
        ]);
    }

    /**
     * Lay down a ready-made set of rates.
     *
     * Skips any state that already has a row rather than overwriting it: a
     * template is a starting point, and a second click must not undo a price
     * somebody set by hand.
     */
    public function applyTemplate(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $templates = StarterTemplates::deliveryRates();

        $validated = $request->validate([
            'template' => ['required', 'string', Rule::in(array_keys($templates))],
        ]);

        $added = 0;
        $skipped = 0;

        foreach ($templates[$validated['template']]['rows'] as $row) {
            $exists = DeliveryRate::query()
                ->when($row['state'] === null,
                    fn ($query) => $query->whereNull('state'),
                    fn ($query) => $query->where('state', $row['state']))
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            DeliveryRate::query()->create($row + [
                'is_active' => true,
                'updated_by' => $request->user()->id,
            ]);
            $added++;
        }

        $auditLogger->log(
            actor: $request->user(),
            subject: $request->user(),
            action: 'admin.delivery_rates_template_applied',
            newValues: ['template' => $validated['template'], 'added' => $added],
        );

        return back()->with(
            $added > 0 ? 'success' : 'error',
            $added > 0
                ? $added.' rate'.($added === 1 ? '' : 's').' added.'
                    .($skipped > 0 ? " {$skipped} left alone — already priced." : ' Edit any of them below.')
                : 'Nothing added — every state in that template already has a rate.',
        );
    }

    public function store(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $rate = DeliveryRate::query()->create(
            $this->validated($request) + ['updated_by' => $request->user()->id]
        );

        $auditLogger->log(
            actor: $request->user(),
            subject: $rate,
            action: 'admin.delivery_rate_created',
            newValues: $rate->only(['state', 'fee_kobo', 'free_threshold_kobo', 'is_active']),
        );

        return back()->with('success', 'Delivery rate added.');
    }

    public function update(Request $request, DeliveryRate $deliveryRate, AuditLoggerContract $auditLogger): RedirectResponse
    {
        if ($deliveryRate->isDefault() && ! $request->boolean('is_active')) {
            return back()->withErrors([
                'rate' => 'The default rate has to stay on — every state without its own rate uses it, and '
                    .'there is no fallback behind it.',
            ]);
        }

        $before = $deliveryRate->only(['state', 'fee_kobo', 'free_threshold_kobo', 'is_active']);

        $deliveryRate->update(
            $this->validated($request, $deliveryRate) + ['updated_by' => $request->user()->id]
        );

        $auditLogger->log(
            actor: $request->user(),
            subject: $deliveryRate,
            action: 'admin.delivery_rate_updated',
            oldValues: $before,
            newValues: $deliveryRate->only(['state', 'fee_kobo', 'free_threshold_kobo', 'is_active']),
        );

        return back()->with('success', 'Delivery rate updated. It applies to new checkouts immediately.');
    }

    public function destroy(Request $request, DeliveryRate $deliveryRate, AuditLoggerContract $auditLogger): RedirectResponse
    {
        // Without the default, every unpriced state silently falls back to the
        // config figure — which is not what the person deleting a row expects.
        if ($deliveryRate->isDefault()) {
            return back()->withErrors([
                'rate' => 'The default rate cannot be deleted — every state without its own rate uses it. Switch it off instead if you want to fall back to the configured fee.',
            ]);
        }

        $auditLogger->log(actor: $request->user(), subject: $deliveryRate, action: 'admin.delivery_rate_deleted');

        $deliveryRate->delete();

        return back()->with('success', 'Delivery rate deleted. That state now uses the default.');
    }

    /**
     * Switch several rates on or off at once.
     */
    public function bulkUpdate(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:activate,deactivate'],
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid'],
        ], [
            'uuids.required' => 'Select at least one rate first.',
        ]);

        $active = $validated['action'] === 'activate';
        $rates = DeliveryRate::query()->whereIn('uuid', $validated['uuids'])->get();

        // Switching the default off would leave unpriced states with no rate
        // at all, and nothing behind it any more.
        if (! $active && $rates->contains(fn (DeliveryRate $rate) => $rate->isDefault())) {
            return back()->withErrors([
                'rate' => 'The default rate cannot be switched off — every state without its own rate uses '
                    .'it, and there is no fallback behind it. Change its amounts instead.',
            ]);
        }

        foreach ($rates as $rate) {
            $rate->update(['is_active' => $active, 'updated_by' => $request->user()->id]);
        }

        $auditLogger->log(
            actor: $request->user(),
            subject: $request->user(),
            action: 'admin.delivery_rates_bulk_'.$validated['action'],
            newValues: ['uuids' => $rates->pluck('uuid')->all()],
        );

        $count = $rates->count();

        return back()->with(
            'success',
            $count.' rate'.($count === 1 ? '' : 's').' '.($active ? 'switched on' : 'switched off').'.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?DeliveryRate $existing = null): array
    {
        $validated = $request->validate([
            // Null means "this is the default row". Unique either way, so a
            // state cannot be priced twice and there is only ever one default.
            'state' => [
                'nullable',
                'string',
                Rule::in(Nigeria::STATES),
                Rule::unique('delivery_rates', 'state')->ignore($existing?->id),
            ],
            'fee_naira' => ['required', 'numeric', 'min:0', 'max:1000000'],
            // Zero (or blank) means never free. There is no inheritance any
            // more: a threshold that came from somewhere the admin could not
            // see is what let a configured fee quietly never apply.
            'free_threshold_naira' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'is_active' => ['boolean'],
            'note' => ['nullable', 'string', 'max:200'],
        ], [
            'state.in' => 'Choose a state from the list.',
            'state.unique' => 'That state already has a delivery rate. Edit the existing one instead.',
        ]);

        // A unique index does not stop this: SQL treats every NULL as
        // distinct, so `unique:state` happily allows a second default row,
        // and which of them wins would then be down to insertion order.
        if (($validated['state'] ?? null) === null) {
            $clash = DeliveryRate::query()
                ->whereNull('state')
                ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->id))
                ->exists();

            if ($clash) {
                throw ValidationException::withMessages([
                    'state' => 'There is already a default rate. Edit that one instead of adding another.',
                ]);
            }
        }

        return [
            'state' => $validated['state'] ?? null,
            'fee_kobo' => (int) round(((float) $validated['fee_naira']) * 100),
            'free_threshold_kobo' => (int) round(((float) ($validated['free_threshold_naira'] ?? 0)) * 100),
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'note' => $validated['note'] ?? null,
        ];
    }
}
