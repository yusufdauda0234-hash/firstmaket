<?php

namespace App\Modules\Support\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Support\Models\Faq;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Services\SupportService;
use App\Shared\Enums\IvrReason;
use App\Shared\Enums\SupportChannel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer Support Center (docs/firstmarket_Implementation_Plan.md Sprint
 * 7): FAQ, WhatsApp entry, hotline/callback request, complaint tickets, and
 * the customer's own ticket threads.
 */
class SupportCenterController extends Controller
{
    public function index(Request $request): Response
    {
        $tickets = SupportTicket::query()
            ->where('customer_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (SupportTicket $ticket) => [
                'uuid' => $ticket->uuid,
                'subject' => $ticket->subject,
                'status' => $ticket->status->value,
                'channel' => $ticket->channel->value,
                'createdAt' => $ticket->created_at->format('j M Y'),
            ]);

        return Inertia::render('Support/Index', [
            'faqs' => Faq::query()->published()->get(['id', 'category', 'question', 'answer'])
                ->groupBy('category')
                ->map(fn ($group) => $group->values())
                ->toArray(),
            'tickets' => $tickets,
            'whatsappNumber' => (string) config('services.support.whatsapp'),
            'hotlineNumber' => (string) config('services.support.hotline'),
            'defaultPhone' => $request->user()->phone,
            'ivrReasons' => array_map(
                fn (IvrReason $reason) => ['value' => $reason->value, 'label' => $reason->label()],
                IvrReason::cases(),
            ),
        ]);
    }

    public function storeTicket(Request $request, SupportService $supportService): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:3000'],
        ]);

        $ticket = $supportService->openTicket(
            customer: $request->user(),
            channel: SupportChannel::Complaint,
            subject: $validated['subject'],
            message: $validated['message'],
        );

        return redirect()
            ->route('support.tickets.show', $ticket->uuid)
            ->with('success', 'Ticket opened — our team will reply here.');
    }

    public function showTicket(Request $request, SupportTicket $ticket): Response
    {
        abort_unless($ticket->customer_id === $request->user()->id, 403);

        $ticket->load(['messages' => fn ($q) => $q->orderBy('id'), 'messages.sender:id,name']);

        return Inertia::render('Support/TicketShow', [
            'ticket' => [
                'uuid' => $ticket->uuid,
                'subject' => $ticket->subject,
                'status' => $ticket->status->value,
                'channel' => $ticket->channel->value,
                'createdAt' => $ticket->created_at->format('j M Y, g:ia'),
                'messages' => $ticket->messages->map(fn ($message) => [
                    'id' => $message->id,
                    'body' => $message->message,
                    'mine' => $message->sender_id === $request->user()->id,
                    'senderName' => $message->sender_id === $request->user()->id
                        ? 'You'
                        : 'FirstMarket Support',
                    'at' => $message->created_at?->format('j M Y, g:ia'),
                ]),
            ],
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket, SupportService $supportService): RedirectResponse
    {
        abort_unless($ticket->customer_id === $request->user()->id, 403);

        $validated = $request->validate(['message' => ['required', 'string', 'max:3000']]);

        $supportService->reply($request->user(), $ticket, $validated['message'], asAgent: false);

        return back()->with('success', 'Reply sent.');
    }

    public function requestHotline(Request $request, SupportService $supportService): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'reason' => ['required', Rule::enum(IvrReason::class)],
        ]);

        $supportService->requestHotline(
            customer: $request->user(),
            phone: $validated['phone'],
            reason: IvrReason::from($validated['reason']),
        );

        return back()->with('success', 'Call request logged — our support line will reach you shortly.');
    }
}
