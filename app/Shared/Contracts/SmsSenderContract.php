<?php

namespace App\Shared\Contracts;

/**
 * Provider-agnostic SMS delivery. Modules always depend on this contract so
 * the concrete gateway (Termii today, anything tomorrow) can be swapped via
 * SMS_PROVIDER_DRIVER without touching feature code
 * (docs/firstmarket_Implementation_Plan.md Sprint 2: SMS provider abstraction).
 */
interface SmsSenderContract
{
    public function send(string $phone, string $message): void;
}
