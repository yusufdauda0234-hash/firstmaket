<?php

namespace App\Shared\Enums;

/**
 * Support ticket priority (docs/firstmarket-Database_Schema.md section 10).
 */
enum TicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
}
