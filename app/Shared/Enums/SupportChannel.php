<?php

namespace App\Shared\Enums;

/**
 * How a support contact reached FirstMaket
 * (docs/FirstMaket-Database_Schema.md section 10).
 */
enum SupportChannel: string
{
    case Faq = 'faq';
    case Whatsapp = 'whatsapp';
    case Hotline = 'hotline';
    case Chat = 'chat';
    case Complaint = 'complaint';
}
