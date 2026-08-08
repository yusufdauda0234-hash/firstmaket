<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\StarterTemplates;
use App\Modules\Orders\Models\PromoCode;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Promo codes.
 *
 * Every code is platform-funded — the discount comes out of FirstMaket's
 * commission and the vendor is paid as though the customer had paid full
 * price. That is what lets a campaign run without asking every vendor's
 * permission, and it is also the reason a code can never discount more than
 * the commission on the basket it is used against.
 *
 * A code is never deleted once it has been used: switching it off stops new
 * redemptions, and the redemption rows stay because they are the only record
 * of what a campaign actually cost.
 */
class PromoCodeController extends Controller
{
    public function index(): Response
    {
        $codes = PromoCode::query()
            ->withCount([
                'redemptions as redemption_count' => fn ($query) => $query->whereNull('released_at'),
            ])
            ->withSum(
                ['redemptions as spend_kobo' => fn ($query) => $query->whereNull('released_at')],
                'discount_kobo',
            )
            ->latest('id')
            ->get()
            ->map(fn (PromoCode $code) => [
                'uuid' => $code->uuid,
                'code' => $code->code,
                'description' => $code->description,
                'type' => $code->type,
                'label' => $code->label(),
                'percentOff' => $code->percent_off === null ? null : (float) $code->percent_off,
                'amountOffNaira' => $code->amount_off_kobo === null ? null : $code->amount_off_kobo / 100,
                'maxDiscountNaira' => $code->max_discount_kobo === null ? null : $code->max_discount_kobo / 100,
                'minOrderNaira' => $code->min_order_kobo / 100,
                'startsAt' => $code->starts_at?->toDateString(),
                'endsAt' => $code->ends_at?->toDateString(),
                'maxRedemptions' => $code->max_redemptions,
                'maxPerCustomer' => $code->max_per_customer,
                'firstOrderOnly' => $code->first_order_only,
                'isActive' => $code->is_active,
                // What it has cost so far, which is the only number that
                // answers "was this campaign worth running".
                'redemptionCount' => (int) $code->redemption_count,
                'spendNaira' => (int) ($code->spend_kobo ?? 0) / 100,
                // Live now, as opposed to merely switched on: a code can be
                // active and still not usable because it has not started, has
                // expired, or has been fully claimed.
                'status' => $this->statusOf($code),
            ]);

        return Inertia::render('Admin/Settings/PromoCodes', [
            'codes' => $codes,
            'templates' => StarterTemplates::forDisplay(StarterTemplates::promoCodes()),
        ]);
    }

    /**
     * Create a ready-made campaign, switched off.
     *
     * Off deliberately: a template is a draft, and a discount going live
     * because somebody was browsing templates is the one mistake this screen
     * must not make.
     */
    public function applyTemplate(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $templates = StarterTemplates::promoCodes();

        $validated = $request->validate([
            'template' => ['required', 'string', Rule::in(array_keys($templates))],
        ]);

        $added = 0;

        foreach ($templates[$validated['template']]['rows'] as $row) {
            if (PromoCode::query()->where('code', $row['code'])->exists()) {
                continue;
            }

            PromoCode::query()->create($row + [
                'max_per_customer' => 1,
                'is_active' => false,
                'created_by' => $request->user()->id,
            ]);
            $added++;
        }

        $auditLogger->log(
            actor: $request->user(),
            subject: $request->user(),
            action: 'admin.promo_codes_template_applied',
            newValues: ['template' => $validated['template'], 'added' => $added],
        );

        return back()->with(
            $added > 0 ? 'success' : 'error',
            $added > 0
                ? 'Draft created, switched off. Check the wording and the limits, then turn it on.'
                : 'Nothing added — that code already exists.',
        );
    }

    public function store(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $code = PromoCode::query()->create(
            $this->validated($request) + ['created_by' => $request->user()->id]
        );

        $auditLogger->log(
            actor: $request->user(),
            subject: $code,
            action: 'admin.promo_code_created',
            newValues: $code->only(['code', 'type', 'percent_off', 'amount_off_kobo', 'max_redemptions']),
        );

        return back()->with('success', $code->code.' created.');
    }

    public function update(Request $request, PromoCode $promoCode, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $before = $promoCode->only(['code', 'type', 'percent_off', 'amount_off_kobo', 'is_active']);

        $promoCode->update($this->validated($request, $promoCode));

        $auditLogger->log(
            actor: $request->user(),
            subject: $promoCode,
            action: 'admin.promo_code_updated',
            oldValues: $before,
            newValues: $promoCode->only(['code', 'type', 'percent_off', 'amount_off_kobo', 'is_active']),
        );

        return back()->with('success', $promoCode->code.' updated.');
    }

    /**
     * Switch a code off. There is no delete.
     *
     * A used code's redemptions are the record of what the campaign cost and
     * of which customer has already had it; deleting the code would cascade
     * them away and let everybody redeem again under a recreated code of the
     * same name.
     */
    public function destroy(Request $request, PromoCode $promoCode, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $promoCode->forceFill(['is_active' => false])->save();

        $auditLogger->log(
            actor: $request->user(),
            subject: $promoCode,
            action: 'admin.promo_code_deactivated',
        );

        return back()->with('success', $promoCode->code.' switched off. Nobody new can use it.');
    }

    /** Why a code is or is not usable right now, in one word for the table. */
    private function statusOf(PromoCode $code): string
    {
        if (! $code->is_active) {
            return 'off';
        }

        if ($code->starts_at?->isFuture()) {
            return 'scheduled';
        }

        if ($code->ends_at !== null && $code->ends_at->isPast()) {
            return 'expired';
        }

        if ($code->max_redemptions !== null && $code->redemption_count >= $code->max_redemptions) {
            return 'claimed';
        }

        return 'live';
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PromoCode $existing = null): array
    {
        $validated = $request->validate([
            // Letters and digits only: a code is read off a flyer and typed
            // by hand, so anything needing a shift key is a support ticket.
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9]+$/',
                Rule::unique('promo_codes', 'code')->ignore($existing?->id),
            ],
            'description' => ['nullable', 'string', 'max:200'],
            'type' => ['required', Rule::in(['percent', 'fixed', 'free_delivery'])],
            'percent_off' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            'amount_off_naira' => ['nullable', 'numeric', 'min:1', 'max:10000000'],
            'max_discount_naira' => ['nullable', 'numeric', 'min:1', 'max:10000000'],
            'min_order_naira' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_per_customer' => ['required', 'integer', 'min:1', 'max:100'],
            'first_order_only' => ['boolean'],
            'is_active' => ['boolean'],
        ], [
            'code.regex' => 'Use letters and numbers only — codes get typed by hand.',
            'ends_at.after' => 'The end date has to be after the start date.',
        ]);

        $type = $validated['type'];

        if ($type === 'percent' && ($validated['percent_off'] ?? null) === null) {
            throw ValidationException::withMessages(['percent_off' => 'Say what percentage comes off.']);
        }

        if ($type === 'fixed' && ($validated['amount_off_naira'] ?? null) === null) {
            throw ValidationException::withMessages(['amount_off_naira' => 'Say how much comes off.']);
        }

        // A percentage with no ceiling is not a promotion, it is an open
        // cheque: 10% off is ₦500 on a kettle and ₦185,000 on a generator,
        // and the second one is a decision nobody made deliberately.
        if ($type === 'percent' && ($validated['max_discount_naira'] ?? null) === null) {
            throw ValidationException::withMessages([
                'max_discount_naira' => 'Set the most this code can ever take off. A percentage without a cap can discount a single expensive order by more than the campaign was meant to cost.',
            ]);
        }

        return [
            'code' => $validated['code'],
            'description' => $validated['description'] ?? null,
            'type' => $type,
            'percent_off' => $type === 'percent'
                ? number_format((float) $validated['percent_off'], 2, '.', '')
                : null,
            'amount_off_kobo' => $type === 'fixed'
                ? (int) round(((float) $validated['amount_off_naira']) * 100)
                : null,
            'max_discount_kobo' => $type === 'percent'
                ? (int) round(((float) $validated['max_discount_naira']) * 100)
                : null,
            'min_order_kobo' => (int) round(((float) ($validated['min_order_naira'] ?? 0)) * 100),
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'max_redemptions' => $validated['max_redemptions'] ?? null,
            'max_per_customer' => (int) $validated['max_per_customer'],
            'first_order_only' => (bool) ($validated['first_order_only'] ?? false),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];
    }
}
