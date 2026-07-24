<?php

namespace App\Shared\Enums;

/**
 * Paystack funding channels offered for wallet deposits
 * (docs/FirstMaket_Implementation_Plan.md Sprint 4). Paystack decides the
 * final channel at checkout; this is the set we request/track.
 */
enum PaymentChannel: string
{
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Ussd = 'ussd';

    /** Channel identifiers as Paystack expects them in the `channels` array. */
    public function paystackChannel(): string
    {
        return match ($this) {
            self::Card => 'card',
            self::BankTransfer => 'bank_transfer',
            self::Ussd => 'ussd',
        };
    }
}
