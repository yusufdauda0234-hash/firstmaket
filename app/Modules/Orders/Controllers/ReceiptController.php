<?php

namespace App\Modules\Orders\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\OrderReceipt;
use App\Shared\Enums\CheckoutMethod;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's receipts: the list, and one document.
 *
 * Read-only by design — a receipt records what was charged on a date, so
 * there is nothing here that edits one.
 */
class ReceiptController extends Controller
{
    public function index(Request $request): Response
    {
        $receipts = OrderReceipt::query()
            ->where('customer_id', $request->user()->id)
            ->latest('issued_at')
            ->paginate(20)
            ->through(fn (OrderReceipt $receipt) => [
                'uuid' => $receipt->uuid,
                'number' => $receipt->receipt_number,
                'totalKobo' => $receipt->total_kobo,
                'itemCount' => array_sum(array_column($receipt->items_snapshot, 'quantity')),
                'method' => $this->methodLabel($receipt->payment_method),
                'paidInFull' => $receipt->isPaidInFull(),
                'issuedAt' => $receipt->issued_at->format('j M Y'),
            ]);

        return Inertia::render('Orders/Receipts', ['receipts' => $receipts]);
    }

    public function show(Request $request, OrderReceipt $receipt): Response
    {
        // A receipt carries a name, an address and a phone number. Ownership
        // is checked on the row, not left to the unguessable uuid.
        abort_unless($receipt->customer_id === $request->user()->id, 403);

        return Inertia::render('Orders/Receipt', [
            'receipt' => [
                'number' => $receipt->receipt_number,
                'issuedAt' => $receipt->issued_at->format('j F Y, g:ia'),
                'currency' => $receipt->currency,
                'items' => $receipt->items_snapshot,
                'subtotalKobo' => $receipt->subtotal_kobo,
                'shippingKobo' => $receipt->shipping_kobo,
                'discountKobo' => $receipt->discount_kobo,
                'totalKobo' => $receipt->total_kobo,
                'paidKobo' => $receipt->paid_kobo,
                'collectOnDeliveryKobo' => $receipt->collect_on_delivery_kobo,
                'method' => $this->methodLabel($receipt->payment_method),
                'reference' => $receipt->payment_reference,
                'billedTo' => $receipt->billed_to,
            ],
        ]);
    }

    /** The stored value is a raw key; the document should read like English. */
    private function methodLabel(?string $method): string
    {
        return CheckoutMethod::tryFrom((string) $method)?->label() ?? 'Payment';
    }
}
