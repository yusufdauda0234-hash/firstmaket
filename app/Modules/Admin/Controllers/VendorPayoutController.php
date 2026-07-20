<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Vendor\Models\VendorPayoutBatch;
use App\Modules\Vendor\Models\VendorPayoutItem;
use App\Modules\Vendor\Services\PayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance vendor payout workspace (docs/firstmarket_Implementation_Plan.md
 * Sprint 6 step 9): generate the weekly batch of cleared earnings, approve
 * it, and record each transfer as paid or failed. The negative ledger entry
 * is written only on paid — never on failure.
 */
class VendorPayoutController extends Controller
{
    public function index(): Response
    {
        $batches = VendorPayoutBatch::query()
            ->withCount('items')
            ->orderByDesc('id')
            ->paginate(15)
            ->through(fn (VendorPayoutBatch $batch) => [
                'uuid' => $batch->uuid,
                'periodStart' => $batch->period_start->format('j M Y'),
                'periodEnd' => $batch->period_end->format('j M Y'),
                'status' => $batch->status->value,
                'totalKobo' => $batch->total_amount_kobo,
                'itemCount' => $batch->items_count,
                'createdAt' => $batch->created_at->format('j M Y'),
            ]);

        return Inertia::render('Admin/Payouts/Index', ['batches' => $batches]);
    }

    public function show(VendorPayoutBatch $batch): Response
    {
        $batch->load(['items.vendor:id,business_name', 'items.bankAccount:id,bank_name,account_name']);

        return Inertia::render('Admin/Payouts/Show', [
            'batch' => [
                'uuid' => $batch->uuid,
                'periodStart' => $batch->period_start->format('j M Y'),
                'periodEnd' => $batch->period_end->format('j M Y'),
                'status' => $batch->status->value,
                'totalKobo' => $batch->total_amount_kobo,
                'approvedAt' => $batch->approved_at?->format('j M Y, g:ia'),
                'items' => $batch->items->map(fn (VendorPayoutItem $item) => [
                    'id' => $item->id,
                    'vendorName' => $item->vendor->business_name,
                    'bankName' => $item->bankAccount->bank_name,
                    'accountName' => $item->bankAccount->account_name,
                    'amountKobo' => $item->amount_kobo,
                    'status' => $item->status->value,
                    'reference' => $item->paystack_transfer_reference,
                    'failureReason' => $item->failure_reason,
                    'paidAt' => $item->paid_at?->format('j M Y'),
                ]),
            ],
        ]);
    }

    public function generate(Request $request, PayoutService $payoutService): RedirectResponse
    {
        $batch = $payoutService->generateBatch($request->user());

        return redirect()
            ->route('admin.payouts.show', $batch->uuid)
            ->with('success', 'Payout batch generated for review.');
    }

    public function approve(Request $request, VendorPayoutBatch $batch, PayoutService $payoutService): RedirectResponse
    {
        $payoutService->approveBatch($request->user(), $batch);

        return back()->with('success', 'Batch approved — record each transfer as it completes.');
    }

    public function markPaid(Request $request, VendorPayoutItem $item, PayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate(['transfer_reference' => ['required', 'string', 'max:100']]);

        $payoutService->markItemPaid($request->user(), $item, $validated['transfer_reference']);

        return back()->with('success', 'Transfer recorded and vendor balance debited.');
    }

    public function markFailed(Request $request, VendorPayoutItem $item, PayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $payoutService->markItemFailed($request->user(), $item, $validated['reason']);

        return back()->with('success', 'Transfer marked failed — vendor balance untouched.');
    }
}
