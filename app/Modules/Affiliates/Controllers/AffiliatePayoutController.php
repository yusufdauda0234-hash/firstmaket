<?php

namespace App\Modules\Affiliates\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Affiliates\Models\AffiliatePayoutBatch;
use App\Modules\Affiliates\Models\AffiliatePayoutItem;
use App\Modules\Affiliates\Services\AffiliatePayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance's view of partner payouts. Held behind `affiliate_payouts.approve`
 * rather than `affiliates.manage` for the same reason returns and refunds are
 * split: reviewing a partner's conversions and sending them money are
 * different levels of trust.
 */
class AffiliatePayoutController extends Controller
{
    public function index(AffiliatePayoutService $payouts): Response
    {
        return Inertia::render('Admin/Affiliates/Payouts', [
            'batches' => AffiliatePayoutBatch::query()
                ->with(['items.affiliate:id,display_name', 'items.bankAccount', 'approvedBy:id,name'])
                ->latest('id')
                ->limit(24)
                ->get()
                ->map(fn (AffiliatePayoutBatch $batch) => [
                    'uuid' => $batch->uuid,
                    'periodStart' => $batch->period_start?->toDateString(),
                    'periodEnd' => $batch->period_end?->toDateString(),
                    'status' => $batch->status->value,
                    'totalKobo' => $batch->total_amount_kobo,
                    'thresholdKobo' => $batch->minimum_threshold_kobo,
                    'approvedBy' => $batch->approvedBy?->name,
                    'approvedAt' => $batch->approved_at?->toDateTimeString(),
                    'items' => $batch->items->map(fn (AffiliatePayoutItem $item) => [
                        'id' => $item->id,
                        'affiliate' => $item->affiliate?->display_name,
                        'amountKobo' => $item->amount_kobo,
                        'status' => $item->status->value,
                        'rejectionReason' => $item->rejection_reason,
                        'failureReason' => $item->failure_reason,
                        'reference' => $item->paystack_transfer_reference,
                        'bank' => $item->bankAccount
                            ? $item->bankAccount->bank_name.' · '.$item->bankAccount->maskedNumber()
                            : null,
                    ])->values(),
                ])->values(),
            'minimumThresholdKobo' => $payouts->minimumThresholdKobo(),
        ]);
    }

    public function generate(Request $request, AffiliatePayoutService $payouts): RedirectResponse
    {
        $validated = $request->validate([
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ]);

        $batch = $payouts->generateBatch(
            $request->user(),
            $validated['period_start'] ?? null,
            $validated['period_end'] ?? null,
        );

        return back()->with(
            $batch->total_amount_kobo > 0 ? 'success' : 'error',
            $batch->total_amount_kobo > 0
                ? 'Payout batch generated for Finance approval.'
                : 'Nothing to pay: no partner is over the minimum threshold with a verified account.',
        );
    }

    public function approve(Request $request, AffiliatePayoutBatch $batch, AffiliatePayoutService $payouts): RedirectResponse
    {
        $payouts->approveBatch($request->user(), $batch);

        return back()->with('success', 'Batch approved. Transfers can now be recorded.');
    }

    public function rejectItem(Request $request, AffiliatePayoutItem $item, AffiliatePayoutService $payouts): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $payouts->rejectItem($request->user(), $item, $validated['reason']);

        return back()->with('success', 'Payout line rejected. The commissions return to pending.');
    }

    public function markPaid(Request $request, AffiliatePayoutItem $item, AffiliatePayoutService $payouts): RedirectResponse
    {
        $validated = $request->validate(['reference' => ['required', 'string', 'max:120']]);
        $payouts->markItemPaid($request->user(), $item, $validated['reference']);

        return back()->with('success', 'Payout recorded as paid.');
    }

    public function markFailed(Request $request, AffiliatePayoutItem $item, AffiliatePayoutService $payouts): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $payouts->markItemFailed($request->user(), $item, $validated['reason']);

        return back()->with('success', 'Payout marked failed. The commissions return to pending for a retry.');
    }
}
