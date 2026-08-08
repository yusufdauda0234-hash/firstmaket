<?php

namespace App\Shared\Enums;

/**
 * Savings balance lifecycle. Deposit-only — there is no withdrawal state
 * because there is no withdrawal path anywhere in the system.
 */
enum SavingsStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';
}
