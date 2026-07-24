<?php

namespace App\Shared\Enums;

/**
 * Wallet lifecycle (docs/FirstMaket-Database_Schema.md section 7). The wallet
 * is deposit-only — there is no withdrawal state because there is no
 * withdrawal path anywhere in the system.
 */
enum WalletStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';
}
