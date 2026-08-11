<?php

namespace App\Modules\Auth\Events;

use App\Shared\Contracts\DomainEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired the first time an account proves either of its channels by OTP.
 * The Affiliates module listens so a referred account reaching verified
 * status is counted — a stronger funnel signal than a bare signup.
 */
class UserIdentifierVerified implements DomainEvent
{
    use Dispatchable;

    public function __construct(public readonly int $userId) {}
}
