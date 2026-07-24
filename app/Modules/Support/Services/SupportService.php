<?php

namespace App\Modules\Support\Services;

use App\Models\User;
use App\Modules\Support\Models\HotlineCallLog;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketMessage;
use App\Modules\Support\Notifications\TicketReplyNotification;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\IvrReason;
use App\Shared\Enums\SupportChannel;
use App\Shared\Enums\TicketPriority;
use App\Shared\Enums\TicketStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns support ticket and hotline state (docs/FirstMaket_Implementation_Plan.md
 * Sprint 7). Agent replies flip a ticket to Pending (waiting on customer),
 * customer replies flip it back to Open; the customer is notified on every
 * agent reply through their Support preference channels.
 */
class SupportService
{
    public function __construct(private readonly AuditLoggerContract $auditLogger) {}

    public function openTicket(
        User $customer,
        SupportChannel $channel,
        string $subject,
        string $message,
        TicketPriority $priority = TicketPriority::Normal,
    ): SupportTicket {
        return DB::transaction(function () use ($customer, $channel, $subject, $message, $priority) {
            $ticket = SupportTicket::query()->create([
                'customer_id' => $customer->id,
                'channel' => $channel,
                'subject' => $subject,
                'status' => TicketStatus::Open,
                'priority' => $priority,
            ]);

            SupportTicketMessage::query()->create([
                'support_ticket_id' => $ticket->id,
                'sender_id' => $customer->id,
                'message' => $message,
                'channel' => $channel->value,
                'created_at' => now(),
            ]);

            $this->auditLogger->log(actor: $customer, subject: $ticket, action: 'support.ticket_opened', newValues: [
                'channel' => $channel->value,
                'subject' => $subject,
            ]);

            return $ticket;
        });
    }

    public function reply(User $sender, SupportTicket $ticket, string $message, bool $asAgent): SupportTicketMessage
    {
        if (in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true) && ! $asAgent) {
            throw ValidationException::withMessages(['message' => 'This ticket is closed. Open a new one if you still need help.']);
        }

        return DB::transaction(function () use ($sender, $ticket, $message, $asAgent) {
            $reply = SupportTicketMessage::query()->create([
                'support_ticket_id' => $ticket->id,
                'sender_id' => $sender->id,
                'message' => $message,
                'channel' => SupportChannel::Chat->value,
                'created_at' => now(),
            ]);

            $ticket->forceFill([
                'status' => $asAgent ? TicketStatus::Pending : TicketStatus::Open,
                // First agent touch auto-assigns the ticket.
                'assigned_to' => $asAgent ? ($ticket->assigned_to ?? $sender->id) : $ticket->assigned_to,
            ])->save();

            if ($asAgent) {
                $ticket->customer->notify(new TicketReplyNotification($ticket->uuid, $ticket->subject));
            }

            return $reply;
        });
    }

    public function updateStatus(User $agent, SupportTicket $ticket, TicketStatus $status): SupportTicket
    {
        $ticket->forceFill([
            'status' => $status,
            'resolved_at' => in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true)
                ? ($ticket->resolved_at ?? now())
                : null,
            'assigned_to' => $ticket->assigned_to ?? $agent->id,
        ])->save();

        $this->auditLogger->log(actor: $agent, subject: $ticket, action: 'support.ticket_status_changed', newValues: [
            'status' => $status->value,
        ]);

        return $ticket;
    }

    /**
     * Hotline/callback request: logs the call with its IVR reason and opens
     * a linked hotline ticket so it lands in the agent queue.
     */
    public function requestHotline(User $customer, string $phone, IvrReason $reason): HotlineCallLog
    {
        return DB::transaction(function () use ($customer, $phone, $reason) {
            $ticket = $this->openTicket(
                customer: $customer,
                channel: SupportChannel::Hotline,
                subject: "Hotline request: {$reason->label()}",
                message: "Customer requested a call on {$phone} about: {$reason->label()}.",
                priority: $reason === IvrReason::PaymentIssue ? TicketPriority::High : TicketPriority::Normal,
            );

            $log = HotlineCallLog::query()->create([
                'customer_id' => $customer->id,
                'support_ticket_id' => $ticket->id,
                'phone' => $phone,
                'reason' => $reason,
                'ivr_selection' => match ($reason) {
                    IvrReason::PaymentIssue => '1',
                    IvrReason::DeliveryIssue => '2',
                    IvrReason::GeneralInquiry => '3',
                },
            ]);

            $this->auditLogger->log(actor: $customer, subject: $log, action: 'support.hotline_requested', newValues: [
                'reason' => $reason->value,
            ]);

            return $log;
        });
    }
}
