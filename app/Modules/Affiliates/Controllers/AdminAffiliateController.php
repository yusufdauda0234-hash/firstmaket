<?php

namespace App\Modules\Affiliates\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliateBankAccount;
use App\Modules\Affiliates\Models\AffiliateCommission;
use App\Modules\Affiliates\Models\AffiliateConversion;
use App\Modules\Affiliates\Models\AffiliateFraudFlag;
use App\Modules\Affiliates\Services\AffiliateFraudService;
use App\Modules\Affiliates\Services\AffiliatePayoutService;
use App\Modules\Affiliates\Services\AffiliateService;
use App\Modules\Affiliates\Services\AffiliateTierResolver;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminAffiliateController extends Controller
{
    public function index(AffiliateTierResolver $tiers): Response
    {
        /*
         * Everything this list needs is gathered up front.
         *
         * Read naively it is four queries per row — a tier needs a count and a
         * sum, and the owed figure needs another — which on a partner
         * programme of any size is the difference between a page and a
         * timeout. The counts come from withCount, the tiers from one batched
         * resolve, and the owed totals from a single grouped sum.
         */
        $affiliates = Affiliate::query()
            ->with(['user:id,name,email', 'tier:id,name', 'bankAccounts'])
            ->withCount([
                'conversions as qualified_count' => fn ($query) => $query->where('status', AffiliateConversion::STATUS_QUALIFIED),
                'fraudFlags as open_flag_count' => fn ($query) => $query->where('status', AffiliateFraudFlag::STATUS_OPEN),
            ])
            ->latest()
            ->get();

        $tierByAffiliate = $tiers->effectiveTiersFor($affiliates);

        $pendingByAffiliate = AffiliateCommission::query()
            ->selectRaw('affiliate_id, COALESCE(SUM(amount_kobo), 0) as owed')
            ->whereIn('affiliate_id', $affiliates->pluck('id'))
            ->where('status', AffiliateCommission::STATUS_PENDING)
            ->groupBy('affiliate_id')
            ->pluck('owed', 'affiliate_id');

        return Inertia::render('Admin/Affiliates/Index', [
            'applications' => $affiliates
                ->map(function (Affiliate $affiliate) use ($tierByAffiliate, $pendingByAffiliate) {
                    $account = $affiliate->bankAccounts->firstWhere('is_active', true);

                    return [
                        'uuid' => $affiliate->id,
                        'name' => $affiliate->display_name,
                        'userName' => $affiliate->user->name,
                        'email' => $affiliate->user->email,
                        'status' => $affiliate->status,
                        'appliedAt' => $affiliate->created_at->toDateString(),
                        'tier' => ($tierByAffiliate[$affiliate->id] ?? null)?->name,
                        'qualifiedCount' => $affiliate->qualified_count,
                        'openFlagCount' => $affiliate->open_flag_count,
                        'suspensionReason' => $affiliate->suspension_reason,
                        'pendingKobo' => (int) ($pendingByAffiliate[$affiliate->id] ?? 0),
                        'bankAccount' => $account ? [
                            'id' => $account->id,
                            'bankName' => $account->bank_name,
                            'accountName' => $account->account_name,
                            'maskedNumber' => $account->maskedNumber(),
                            'verified' => $account->verified_at !== null,
                        ] : null,
                    ];
                }),
            // Conversions needing a decision, newest first — the queue this
            // screen exists for.
            'reviewQueue' => AffiliateConversion::query()
                ->with(['affiliate:id,display_name', 'fraudFlags'])
                ->where('status', AffiliateConversion::STATUS_REVIEW)
                ->latest('id')
                ->limit(100)
                ->get()
                ->map(fn (AffiliateConversion $conversion) => [
                    'id' => $conversion->id,
                    'affiliate' => $conversion->affiliate?->display_name,
                    'type' => $conversion->conversion_type,
                    'valueKobo' => $conversion->order_value_kobo,
                    'qualifiedAt' => $conversion->qualified_at?->toDateTimeString(),
                    'flags' => $conversion->fraudFlags->map(fn (AffiliateFraudFlag $flag) => [
                        'id' => $flag->id,
                        'reason' => $flag->reason,
                        'detail' => $flag->detail,
                        'status' => $flag->status,
                    ])->values(),
                ])->values(),
        ]);
    }

    public function approve(Affiliate $affiliate, Request $request, AffiliateService $affiliates): RedirectResponse
    {
        $affiliates->approve($affiliate, $request->user());

        return back()->with('success', 'Affiliate approved and link generated.');
    }

    public function reject(Affiliate $affiliate, Request $request, AffiliateService $affiliates): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $affiliates->reject($affiliate, $validated['reason']);

        return back()->with('success', 'Affiliate application rejected.');
    }

    public function suspend(Affiliate $affiliate, Request $request, AffiliateService $affiliates, AuditLoggerContract $audit): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $affiliates->suspend($affiliate, $validated['reason']);

        $audit->log(
            actor: $request->user(),
            subject: $affiliate,
            action: 'affiliate.suspended',
            newValues: ['reason' => $validated['reason']],
        );

        return back()->with('success', 'Affiliate suspended. They keep what they have already earned but cannot earn more.');
    }

    public function reinstate(Affiliate $affiliate, Request $request, AffiliateService $affiliates, AuditLoggerContract $audit): RedirectResponse
    {
        $affiliates->reinstate($affiliate);
        $audit->log(actor: $request->user(), subject: $affiliate, action: 'affiliate.reinstated');

        return back()->with('success', 'Affiliate reinstated.');
    }

    public function approveConversion(AffiliateConversion $conversion, Request $request, AuditLoggerContract $audit): RedirectResponse
    {
        $conversion->forceFill(['status' => AffiliateConversion::STATUS_QUALIFIED])->save();
        $conversion->fraudFlags()->where('status', AffiliateFraudFlag::STATUS_OPEN)->update([
            'status' => AffiliateFraudFlag::STATUS_DISMISSED,
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        // Priced only now: a conversion under review never had a commission
        // written for it, so clearing it is what creates the earning.
        $tiers = app(AffiliateTierResolver::class);
        $affiliate = $conversion->affiliate;

        if ($affiliate !== null) {
            $amount = $tiers->commissionFor($affiliate, $conversion);

            if ($amount > 0) {
                AffiliateCommission::query()->firstOrCreate(
                    ['conversion_id' => $conversion->id],
                    ['affiliate_id' => $affiliate->id, 'amount_kobo' => $amount, 'status' => AffiliateCommission::STATUS_PENDING],
                );
            }
        }

        $audit->log(actor: $request->user(), subject: $conversion, action: 'affiliate.conversion_approved');

        return back()->with('success', 'Conversion cleared and commission recorded.');
    }

    public function rejectConversion(AffiliateConversion $conversion, Request $request, AffiliateService $affiliates, AuditLoggerContract $audit): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $affiliates->rejectConversion($conversion, $validated['reason']);

        $audit->log(
            actor: $request->user(),
            subject: $conversion,
            action: 'affiliate.conversion_rejected',
            newValues: ['reason' => $validated['reason']],
        );

        return back()->with('success', 'Conversion rejected. Its commission can no longer be paid.');
    }

    public function resolveFlag(AffiliateFraudFlag $flag, Request $request, AffiliateFraudService $fraud): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([AffiliateFraudFlag::STATUS_CONFIRMED, AffiliateFraudFlag::STATUS_DISMISSED])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $fraud->resolve($flag, $request->user(), $validated['status'], $validated['note'] ?? null);

        return back()->with('success', 'Flag resolved.');
    }

    public function verifyBankAccount(AffiliateBankAccount $account, Request $request, AffiliatePayoutService $payouts): RedirectResponse
    {
        $payouts->verifyBankAccount($request->user(), $account);

        return back()->with('success', 'Payout account verified.');
    }
}
