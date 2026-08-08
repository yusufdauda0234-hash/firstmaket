<?php

namespace App\Shared\Enums;

/**
 * Lifecycle of a Paystack charge (docs/FirstMaket-Database_Schema.md
 * section 7). Savings is credited only when a charge reaches Success via a
 * verified webhook — never from the browser callback.
 */
enum PaystackTransactionStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
}
