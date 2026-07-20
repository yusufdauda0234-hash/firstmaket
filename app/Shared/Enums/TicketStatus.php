<?php

namespace App\Shared\Enums;

/**
 * Support ticket lifecycle (docs/firstmarket-Database_Schema.md section 10).
 */
enum TicketStatus: string
{
    case Open = 'open';
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Closed = 'closed';
}
