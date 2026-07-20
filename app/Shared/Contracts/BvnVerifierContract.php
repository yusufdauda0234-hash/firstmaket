<?php

namespace App\Shared\Contracts;

use App\Models\User;

/**
 * BVN check against the configured provider (Paystack Identity Verification
 * by default, BVN_PROVIDER_DRIVER). Implementations must never log or store
 * the raw BVN (docs/firstmarket_Security_Compliance.md).
 */
interface BvnVerifierContract
{
    public function verify(User $user, string $bvn): IdentityCheckResult;
}
