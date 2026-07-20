<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Support\Models\HotlineCallLog;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Services\SupportService;
use App\Shared\Enums\TicketStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Support Agent workspace (docs/firstmarket_Implementation_Plan.md Sprint
 * 7): ticket queue, hotline queue, ticket thread with replies and status
 * changes. Guarded by permission:support.manage on the admin subdomain.
 */
class SupportAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status');

        $tickets = SupportTicket::query()
            ->with(['customer:id,name', 'assignee:id,name'])
            ->when($status !== null && $status !== '', fn ($q) => $q->where('status', $status))
            ->orderByRaw("field(status, 'open', 'pending', 'resolved', 'closed')")
            ->orderByDesc('priority')
            ->orderBy('updated_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SupportTicket $ticket) => [
                'uuid' => $ticket->uuid,
                'subject' => $ticket->subject,
                'customerName' => $ticket->customer->name,
                'assigneeName' => $ticket->assignee?->name,
                'channel' => $ticket->channel->value,
                'status' => $ticket->status->value,
                'priority' => $ticket->priority->value,
                'createdAt' => $ticket->created_at->format('j M Y'),
            ]);

        $hotline = HotlineCallLog::query()
            ->with(['customer:id,name', 'ticket:id,uuid,status'])
            ->whereNull('ended_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (HotlineCallLog $log) => [
                'id' => $log->id,
                'customerName' => $log->customer->name,
                'phone' => $log->phone,
                'reason' => $log->reason->label(),
                'ticketUuid' => $log->ticket?->uuid,
                'requestedAt' => $log->created_at->format('j M Y, g:ia'),
            ]);

        return Inertia::render('Admin/Support/Index', [
            'tickets' => $tickets,
            'hotlineQueue' => $hotline,
            'filters' => ['status' => $status],
            'openCount' => SupportTicket::query()->where('status', TicketStatus::Open)->count(),
        ]);
    }

    public function show(SupportTicket $ticket): Response
    {
        $ticket->load([
            'customer:id,name,email,phone',
            'assignee:id,name',
            'messages' => fn ($q) => $q->orderBy('id'),
            'messages.sender:id,name',
        ]);

        return Inertia::render('Admin/Support/Show', [
            'ticket' => [
                'uuid' => $ticket->uuid,
                'subject' => $ticket->subject,
                'status' => $ticket->status->value,
                'priority' => $ticket->priority->value,
                'channel' => $ticket->channel->value,
                'customer' => [
                    'id' => $ticket->customer->id,
                    'name' => $ticket->customer->name,
                    'email' => $ticket->customer->email,
                    'phone' => $ticket->customer->phone,
                ],
                'assigneeName' => $ticket->assignee?->name,
                'createdAt' => $ticket->created_at->format('j M Y, g:ia'),
                'messages' => $ticket->messages->map(fn ($message) => [
                    'id' => $message->id,
                    'body' => $message->message,
                    'fromCustomer' => $message->sender_id === $ticket->customer_id,
                    'senderName' => $message->sender->name,
                    'at' => $message->created_at?->format('j M Y, g:ia'),
                ]),
            ],
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket, SupportService $supportService): RedirectResponse
    {
        $validated = $request->validate(['message' => ['required', 'string', 'max:3000']]);

        $supportService->reply($request->user(), $ticket, $validated['message'], asAgent: true);

        return back()->with('success', 'Reply sent to the customer.');
    }

    public function updateStatus(Request $request, SupportTicket $ticket, SupportService $supportService): RedirectResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::enum(TicketStatus::class)]]);

        $supportService->updateStatus($request->user(), $ticket, TicketStatus::from($validated['status']));

        return back()->with('success', 'Ticket status updated.');
    }
}
