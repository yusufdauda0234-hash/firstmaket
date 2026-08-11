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
 * The vendor's side: what came back, and whether it matches what was claimed.
 *
 * Deliberately only two actions. A vendor confirms receipt — a fact they are
 * best placed to report — and may contest the condition, which escalates to an
 * admin. Neither approves, rejects, nor refunds anything: the vendor is the
 * party who loses the sale, so they do not get to decide it.
 */
class VendorReturnController extends Controller
{
    public function __construct(private readonly ReturnService $returns) {}

    public function index(Request $request): Response
    {
        $vendorId = $request->user()->vendorProfile?->id;

        $returns = ReturnRequest::query()
            ->where('vendor_id', $vendorId)
            ->with(['order.product:id,name', 'order:id,uuid,product_id'])
            ->latest('id')
            ->get()
            ->map(fn (ReturnRequest $return) => [
                'uuid' => $return->uuid,
                'status' => $return->status->value,
                'statusLabel' => $return->status->label(),
                'reason' => $return->reason->value,
                'reasonLabel' => $return->reason->label(),
                'reasonNote' => $return->reason_note,
                'productName' => $return->order?->product?->name,
                'orderUuid' => $return->order?->uuid,
                'openedAt' => $return->created_at->format('j M Y'),
                'requiredUnopened' => $return->required_unopened,
                // What the vendor is allowed to do from here.
                'canMarkReceived' => in_array(
                    $return->status,
                    [ReturnStatus::InTransit, ReturnStatus::Approved],
                    true,
                ),
                'canContest' => in_array(
                    $return->status,
                    [ReturnStatus::Received, ReturnStatus::InTransit],
                    true,
                ),
            ]);

        return Inertia::render('Vendor/Returns/Index', ['returns' => $returns]);
    }

    public function markReceived(Request $request, ReturnRequest $return): RedirectResponse
    {
        $this->authorizeVendor($request, $return);

        $this->returns->markReceived($request->user(), $return);

        return back()->with('success', 'Marked as received. Our team will review and settle it.');
    }

    public function contest(Request $request, ReturnRequest $return): RedirectResponse
    {
        $this->authorizeVendor($request, $return);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $this->returns->contest($request->user(), $return, $validated['reason']);

        return back()->with('success', 'Sent to our team to review. We will come back to you.');
    }

    private function authorizeVendor(Request $request, ReturnRequest $return): void
    {
        abort_unless($return->vendor_id === $request->user()->vendorProfile?->id, 403);
    }
}
