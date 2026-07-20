<?php

namespace App\Shared\Contracts;

use App\Models\User;

/**
 * NIN check against the configured provider (Youverify by default,
 * NIN_PROVIDER_DRIVER; Smile Identity and Prembly are the planned
 * alternatives). Implementations must never log or store the raw NIN.
 */
interface NinVerifierContract
{
    public function verify(User $user, string $nin): IdentityCheckResult;
}
