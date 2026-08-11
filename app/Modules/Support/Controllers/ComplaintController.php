<?php

namespace App\Modules\Support\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Services\SupportService;
use App\Shared\Enums\ComplaintCategory;
use App\Shared\Enums\SupportChannel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Complaint Centre.
 *
 * A thin front door onto the existing ticket system: filing a complaint opens
 * a ticket on the Complaint channel, so it lands in the same queue staff
 * already work and inherits threading, assignment and audit for free.
 *
 * The customer picks what went wrong, not how urgent it is — the category
 * decides that. Everyone marks their own problem urgent, which makes a
 * customer-set priority worth nothing.
 */
class ComplaintController extends Controller
{
    public function __construct(private readonly SupportService $support) {}

    public function create(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Support/Complaints/Create', [
            'categories' => ComplaintCategory::options(),
            // Recent orders, so a complaint can be attached to one rather than
            // described from memory.
            'orders' => Order::query()
                ->where('customer_id', $user->id)
                ->with('product:id,name')
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (Order $order) => [
                    'uuid' => $order->uuid,
                    'label' => ($order->product?->name ?? 'Order').' — '.$order->created_at->format('j M Y'),
                ]),
            'complaints' => SupportTicket::query()
                ->where('customer_id', $user->id)
                ->where('channel', SupportChannel::Complaint)
                ->latest('id')
                ->get()
                ->map(fn (SupportTicket $ticket) => [
                    'uuid' => $ticket->uuid,
                    'subject' => $ticket->subject,
                    'category' => $ticket->complaint_category?->label(),
                    'status' => $ticket->status->value,
                    'openedAt' => $ticket->created_at->format('j M Y'),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'category' => ['required', Rule::enum(ComplaintCategory::class)],
            'subject' => ['required', 'string', 'min:5', 'max:120'],
            'message' => ['required', 'string', 'min:20', 'max:2000'],
            'order_uuid' => ['nullable', 'string', 'exists:orders,uuid'],
        ]);

        $order = $validated['order_uuid'] ?? null
            ? Order::query()->where('uuid', $validated['order_uuid'])->first()
            : null;

        $ticket = $this->support->openComplaint(
            customer: $request->user(),
            category: ComplaintCategory::from($validated['category']),
            subject: $validated['subject'],
            message: $validated['message'],
            aboutOrder: $order,
        );

        return redirect()
            ->route('support.tickets.show', $ticket->uuid)
            ->with('success', 'Complaint filed. Our team will come back to you here.');
    }
}
