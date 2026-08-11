<?php

namespace App\Modules\Returns\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Returns\Services\ReturnService;
use App\Shared\Enums\ReturnStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff review of returns, and the one place a refund is authorised.
 *
 * Split across two permissions on purpose: `returns.manage` lets an agent work
 * the queue — approve, reject, chase — while `refunds.issue` is what actually
 * moves money back out. They are different levels of trust, and collapsing
 * them would mean every support agent could pay money out of the business.
 */
class AdminReturnController extends Controller
{
    public function __construct(private readonly ReturnService $returns) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', '');

        $returns = ReturnRequest::query()
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->with([
                'order:id,uuid,product_id,locked_price_kobo',
                'order.product:id,name',
                'customer:id,name,email',
                'vendor:id,business_name',
            ])
            // Oldest first: a returns queue is a promise with a clock on it.
            ->orderByRaw("field(status, 'requested', 'disputed', 'received', 'in_transit', 'approved', 'refunded', 'rejected', 'cancelled')")
            ->orderBy('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ReturnRequest $return) => [
                'uuid' => $return->uuid,
                'status' => $return->status->value,
                'statusLabel' => $return->status->label(),
                'reason' => $return->reason->value,
                'reasonLabel' => $return->reason->label(),
                'reasonNote' => $return->reason_note,
                'refundableKobo' => $return->refundable_kobo,
                'returnDeliveryPaidBy' => $return->return_delivery_paid_by,
                'customerName' => $return->customer?->name,
                'vendorName' => $return->vendor?->business_name,
                'productName' => $return->order?->product?->name,
                'orderUuid' => $return->order?->uuid,
                'openedAt' => $return->created_at->format('j M Y'),
                // A plan order can only ever return value as plan credit.
                'refundsToPlan' => $return->order?->savings_goal_id !== null,
                'canDecide' => in_array(
                    $return->status,
                    [ReturnStatus::Requested, ReturnStatus::Disputed, ReturnStatus::Received],
                    true,
                ),
                'canRefund' => in_array(
                    $return->status,
                    [ReturnStatus::Received, ReturnStatus::Disputed, ReturnStatus::Approved],
                    true,
                ),
            ]);

        return Inertia::render('Admin/Returns/Index', [
            'returns' => $returns,
            'filters' => ['status' => $status],
            'statuses' => array_map(
                fn (ReturnStatus $case) => ['value' => $case->value, 'label' => $case->label()],
                ReturnStatus::cases(),
            ),
            'canIssueRefunds' => $request->user()->can('refunds.issue'),
        ]);
    }

    public function approve(Request $request, ReturnRequest $return): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $this->returns->approve($request->user(), $return, $validated['note'] ?? null);

        return back()->with('success', 'Return approved. The customer has been told how to send it back.');
    }

    public function reject(Request $request, ReturnRequest $return): RedirectResponse
    {
        // A rejection always carries a reason: the customer is owed an
        // explanation, and an unexplained refusal is what turns a return into
        // a chargeback.
        $validated = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);

        $this->returns->reject($request->user(), $return, $validated['reason']);

        return back()->with('success', 'Return rejected and the customer notified.');
    }

    public function refund(Request $request, ReturnRequest $return): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $this->returns->refund($request->user(), $return, $validated['note'] ?? null);

        return back()->with('success', 'Refund issued, and the vendor earning reversed.');
    }
}
